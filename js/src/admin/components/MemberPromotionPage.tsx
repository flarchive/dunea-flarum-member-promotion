import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Placeholder from 'flarum/common/components/Placeholder';
import Switch from 'flarum/common/components/Switch';
import type Mithril from 'mithril';

type Tab = 'settings' | 'rules' | 'users' | 'status';

const tabIcons: Record<Tab, string> = {
  settings: 'fas fa-cogs',
  rules: 'fas fa-sitemap',
  users: 'fas fa-user-check',
  status: 'fas fa-heartbeat',
};

const emptyRule = () => ({
  name: '', description: '', enabled: false, priority: 0, prerequisiteGroupId: '', targetGroupId: '',
  removePrerequisite: false, ageEnabled: true, ageValue: 3, ageUnit: 'days', ageOperator: '>',
  repliesEnabled: true, repliesCount: 10, repliesOperator: '>',
});

export default class MemberPromotionPage extends ExtensionPage {
  tab: Tab = 'settings';
  loading = true;
  saving = false;
  data: any = null;
  userQuery = '';
  users: any[] = [];
  userSearched = false;
  searchingUsers = false;
  inspectingUserId: number | null = null;
  userActionLoading = '';
  selectedUser: any = null;
  logs: any[] = [];
  nextLog: number | null = null;
  logFilters = { user: '', status: '' };
  loadingLogs = false;
  status: any = null;
  loadingStatus = false;
  ruleAction = '';
  error = '';

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    this.load();
  }

  api(path: string) {
    return app.forum.attribute('apiUrl') + '/member-promotion' + path;
  }

  async load() {
    this.loading = true;
    try {
      this.data = await app.request({ method: 'GET', url: this.api('/dashboard') });
    } catch (error: any) {
      this.error = error?.message || String(error);
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  content() {
    if (this.loading) return <LoadingIndicator />;
    if (!this.data) return <Placeholder text={this.error || this.t('load_failed')} />;

    return (
      <div className="MemberPromotionPage">
        <div className="MemberPromotionPage-tabsBar">
          <div className="container">
            <nav className="MemberPromotionPage-tabs" role="tablist" aria-label={this.t('tabs.label') as string}>
              {(['settings', 'rules', 'users', 'status'] as Tab[]).map((tab) => (
                <Button
                  className={'Button MemberPromotionPage-tab' + (this.tab === tab ? ' is-active' : '')}
                  icon={tabIcons[tab]}
                  role="tab"
                  aria-selected={this.tab === tab ? 'true' : 'false'}
                  onclick={() => this.openTab(tab)}
                >
                  {this.t(`tabs.${tab}`)}
                </Button>
              ))}
            </nav>
          </div>
        </div>
        <div className="container">
          <div className="MemberPromotionPage-content">
            {this.error && <div className="Alert Alert--error">{this.error}</div>}
            {this.tab === 'settings' && this.settingsView()}
            {this.tab === 'rules' && this.rulesView()}
            {this.tab === 'users' && this.usersAndLogsView()}
            {this.tab === 'status' && this.statusView()}
          </div>
        </div>
      </div>
    );
  }

  openTab(tab: Tab) {
    this.tab = tab;
    this.error = '';
    if (tab === 'users' && !this.logs.length) this.loadLogs();
    if (tab === 'status') this.loadStatus();
  }

  settingsView() {
    const settings = this.data.settings;
    return (
      <section className="MemberPromotionPage-section">
        <h2>{this.t('settings.title')}</h2>
        <div className="MemberPromotionPage-panel">
          <div className="Form-group">
            <Switch state={settings.enabled} onchange={(value: boolean) => (settings.enabled = value)}>
              {this.t('settings.enabled')}
            </Switch>
            <p className="helpText">{this.t('settings.enabled_help')}</p>
            <div className="Alert Alert--warning MemberPromotionPage-schedulerNotice" role="note">
              <i className="icon fas fa-clock" aria-hidden="true" />
              <div>
                <strong>{this.t('settings.scheduler_required')}</strong>
                <p>{this.t('settings.scheduler_required_help')}</p>
              </div>
            </div>
          </div>
          <div className="Form-group">
            <label>{this.t('settings.excluded_groups')}</label>
            {this.data.groups.length ? (
              <div className="MemberPromotionPage-checks">
                {this.data.groups.map((group: any) => (
                  <label className="checkbox">
                    <input
                      type="checkbox"
                      checked={settings.excludedGroupIds.map(Number).includes(Number(group.id))}
                      onchange={(event: Event) => this.toggleExcluded(Number(group.id), (event.target as HTMLInputElement).checked)}
                    />
                    {group.name_singular}
                  </label>
                ))}
              </div>
            ) : (
              <p className="helpText MemberPromotionPage-emptyHint">{this.t('settings.no_excluded_groups')}</p>
            )}
          </div>
          <div className="MemberPromotionPage-grid MemberPromotionPage-settingsGrid">
            {this.numberField('settings.retention', settings.logRetentionDays, 1, 3650, (value) => (settings.logRetentionDays = value))}
          </div>
          <div className="MemberPromotionPage-formActions">
            <Button className="Button Button--primary" loading={this.saving} onclick={() => this.saveSettings()}>{this.t('save')}</Button>
          </div>
        </div>
      </section>
    );
  }

  toggleExcluded(id: number, checked: boolean) {
    const ids = this.data.settings.excludedGroupIds.map(Number).filter((value: number) => value !== id);
    this.data.settings.excludedGroupIds = checked ? [...ids, id] : ids;
  }

  async saveSettings() {
    if (await this.request('POST', '/settings', this.data.settings)) this.success('messages.settings_saved');
  }

  rulesView() {
    return (
      <section className="MemberPromotionPage-section">
        <div className="MemberPromotionPage-heading">
          <h2>{this.t('rules.title')}</h2>
          <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => this.openRuleModal(emptyRule())}>{this.t('rules.new')}</Button>
        </div>
        {this.data.rules.length ? <div className="MemberPromotionPage-tableWrap">
          <table className="MemberPromotionPage-table MemberPromotionPage-table--rules">
            <thead><tr><th>{this.t('rules.name')}</th><th>{this.t('rules.prerequisite')}</th><th>{this.t('rules.conditions')}</th><th>{this.t('rules.target')}</th><th>{this.t('rules.order')}</th><th>{this.t('actions')}</th></tr></thead>
            <tbody>
              {this.data.rules.map((rule: any) => (
                <tr>
                  <td className="MemberPromotionPage-ruleName" data-label={this.t('rules.name') as string}><strong>{rule.name}</strong><small>{rule.enabled ? this.t('enabled') : this.t('disabled')} · v{rule.version}</small></td>
                  <td className="MemberPromotionPage-rulePrerequisite" data-label={this.t('rules.prerequisite') as string}>{this.groupName(rule.prerequisite_group_id) || this.t('rules.none')}</td>
                  <td className="MemberPromotionPage-ruleConditions" data-label={this.t('rules.conditions') as string}>{this.conditionText(rule)}</td>
                  <td className="MemberPromotionPage-ruleTarget" data-label={this.t('rules.target') as string}>{this.groupName(rule.target_group_id)}</td>
                  <td className="MemberPromotionPage-ruleOrder" data-label={this.t('rules.order') as string}>{rule.priority} / #{rule.id}</td>
                  <td className="MemberPromotionPage-actions" data-label={this.t('actions') as string}>
                    <div className="MemberPromotionPage-actionsButtons">
                      <Button className="Button Button--icon" icon="fas fa-edit" aria-label={this.t('edit') as string} title={this.t('edit')} disabled={!!this.ruleAction} onclick={() => this.editRule(rule)} />
                      <Button className="Button Button--icon" icon="fas fa-copy" aria-label={this.t('copy') as string} title={this.t('copy')} loading={this.ruleAction === `copy-${rule.id}`} disabled={!!this.ruleAction} onclick={() => this.copyRule(rule.id, rule.name)} />
                      <Button className="Button Button--icon Button--danger" icon="fas fa-trash-alt" aria-label={this.t('delete') as string} title={this.t('delete')} loading={this.ruleAction === `delete-${rule.id}`} disabled={!!this.ruleAction} onclick={() => this.deleteRule(rule.id, rule.name)} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div> : <div className="MemberPromotionPage-emptyState MemberPromotionPage-emptyState--wide"><i className="icon fas fa-sitemap" />{this.t('rules.empty')}</div>}
      </section>
    );
  }

  ruleFields(rule: any, saving: boolean, cancel: () => void) {
    return (
      <div className="MemberPromotionPage-ruleForm">
        <div className="MemberPromotionPage-grid MemberPromotionPage-ruleBasics">
          {this.textField('rules.name', rule.name, (value) => (rule.name = value), true, 'rules.name_placeholder')}
          {this.numberField('rules.priority', rule.priority, -100000, 100000, (value) => (rule.priority = value))}
        </div>
        <div className="Form-group"><label for="member-promotion-rule-description">{this.t('rules.description')}</label><textarea id="member-promotion-rule-description" name="member-promotion-rule-description" className="FormControl" value={rule.description || ''} placeholder={this.t('rules.description_placeholder') as string} oninput={(e: Event) => (rule.description = (e.target as HTMLTextAreaElement).value)} /></div>
        <div className="MemberPromotionPage-grid MemberPromotionPage-ruleGroups">
          {this.groupSelect('rules.prerequisite', rule.prerequisiteGroupId, true, (value) => (rule.prerequisiteGroupId = value))}
          <div>
            {this.groupSelect('rules.target', rule.targetGroupId, false, (value) => (rule.targetGroupId = value))}
            {!this.data.groups.length && <p className="helpText MemberPromotionPage-fieldWarning"><i className="icon fas fa-info-circle" />{this.t('rules.no_groups')}</p>}
          </div>
        </div>
        <div className="MemberPromotionPage-condition">
          <Switch state={rule.ageEnabled} onchange={(value: boolean) => (rule.ageEnabled = value)}>{this.t('rules.age')}</Switch>
          <div className="MemberPromotionPage-conditionControls">
            <select name="member-promotion-age-operator" aria-label={`${this.t('rules.age')} ${this.t('rules.operator')}`} className="FormControl" value={rule.ageOperator} onchange={(e: Event) => (rule.ageOperator = (e.target as HTMLSelectElement).value)}><option value=">">&gt;</option><option value=">=">≥</option></select>
            <input name="member-promotion-age-value" aria-label={`${this.t('rules.age')} ${this.t('rules.value')}`} className="FormControl" type="number" min="0" step="1" value={rule.ageValue} oninput={(e: Event) => (rule.ageValue = Number((e.target as HTMLInputElement).value))} />
            <select name="member-promotion-age-unit" aria-label={`${this.t('rules.age')} ${this.t('rules.unit')}`} className="FormControl" value={rule.ageUnit} onchange={(e: Event) => (rule.ageUnit = (e.target as HTMLSelectElement).value)}><option value="hours">{this.t('rules.hours')}</option><option value="days">{this.t('rules.days')}</option></select>
          </div>
        </div>
        <div className="MemberPromotionPage-condition">
          <Switch state={rule.repliesEnabled} onchange={(value: boolean) => (rule.repliesEnabled = value)}>{this.t('rules.replies')}</Switch>
          <div className="MemberPromotionPage-conditionControls MemberPromotionPage-conditionControls--short">
            <select name="member-promotion-replies-operator" aria-label={`${this.t('rules.replies')} ${this.t('rules.operator')}`} className="FormControl" value={rule.repliesOperator} onchange={(e: Event) => (rule.repliesOperator = (e.target as HTMLSelectElement).value)}><option value=">">&gt;</option><option value=">=">≥</option></select>
            <input name="member-promotion-replies-value" aria-label={`${this.t('rules.replies')} ${this.t('rules.value')}`} className="FormControl" type="number" min="0" step="1" value={rule.repliesCount} oninput={(e: Event) => (rule.repliesCount = Number((e.target as HTMLInputElement).value))} />
          </div>
        </div>
        <div className="MemberPromotionPage-grid MemberPromotionPage-ruleOptions">
          <Switch state={rule.removePrerequisite} onchange={(value: boolean) => (rule.removePrerequisite = value)}>{this.t('rules.remove_prerequisite')}</Switch>
          <Switch state={rule.enabled} onchange={(value: boolean) => (rule.enabled = value)}>{this.t('rules.enabled')}</Switch>
        </div>
        <div className="MemberPromotionPage-formActions"><Button type="submit" className="Button Button--primary" loading={saving} disabled={saving || !this.data.groups.length}>{this.t('save')}</Button><Button className="Button" disabled={saving} onclick={cancel}>{this.t('cancel')}</Button></div>
      </div>
    );
  }

  openRuleModal(rule: any) {
    this.error = '';
    app.modal.show(RuleModal, { page: this, rule });
  }

  editRule(source: any) {
    this.openRuleModal({
      id: source.id, name: source.name, description: source.description || '', enabled: !!source.enabled, priority: source.priority,
      prerequisiteGroupId: source.prerequisite_group_id || '', targetGroupId: source.target_group_id,
      removePrerequisite: !!source.remove_prerequisite, ageEnabled: !!source.age_enabled,
      ageValue: source.age_minutes % 1440 === 0 ? source.age_minutes / 1440 : source.age_minutes / 60,
      ageUnit: source.age_minutes % 1440 === 0 ? 'days' : 'hours', ageOperator: source.age_operator,
      repliesEnabled: !!source.replies_enabled, repliesCount: source.replies_count || 0, repliesOperator: source.replies_operator,
    });
  }

  async copyRule(id: number, name: string) {
    if (this.ruleAction) return;
    if (!window.confirm(this.plainText('rules.copy_confirm', { name }))) return;
    this.ruleAction = `copy-${id}`;
    try {
      if (await this.request('POST', `/rules/${id}/copy`, {})) {
        await this.load();
        this.success('messages.rule_copied');
      }
    } finally {
      this.ruleAction = '';
      m.redraw();
    }
  }

  async deleteRule(id: number, name: string) {
    if (this.ruleAction) return;
    if (!window.confirm(this.plainText('rules.delete_confirm', { name }))) return;
    this.ruleAction = `delete-${id}`;
    try {
      if (await this.request('DELETE', `/rules/${id}`, {})) {
        await this.load();
        this.success('messages.rule_deleted');
      }
    } finally {
      this.ruleAction = '';
      m.redraw();
    }
  }

  usersAndLogsView() {
    return (
      <div className="MemberPromotionPage-combined">
        {this.usersView()}
        {this.logsView()}
      </div>
    );
  }

  usersView() {
    return (
      <section className="MemberPromotionPage-section">
        <h2>{this.t('users.title')}</h2>
        <form className="MemberPromotionPage-search" onsubmit={(event: Event) => { event.preventDefault(); this.searchUsers(); }}>
          <input name="member-promotion-user-search" aria-label={this.t('users.search') as string} className="FormControl" value={this.userQuery} placeholder={this.t('users.search') as string} oninput={(e: Event) => (this.userQuery = (e.target as HTMLInputElement).value)} />
          <Button type="submit" className="Button Button--primary" icon="fas fa-search" loading={this.searchingUsers} disabled={this.searchingUsers || !this.userQuery.trim()}>{this.t('users.search_button')}</Button>
        </form>
        <div className="MemberPromotionPage-userResults">
          {this.users.map((user) => {
            const selected = Number(this.selectedUser?.userId) === Number(user.id);
            return <Button className={'Button' + (selected ? ' is-active' : '')} icon="fas fa-user" aria-pressed={selected ? 'true' : 'false'} loading={this.inspectingUserId === Number(user.id)} disabled={this.inspectingUserId !== null || !!this.userActionLoading} onclick={() => this.inspectUser(user.id)}>#{user.id} {user.username}</Button>;
          })}
        </div>
        {this.userSearched && !this.searchingUsers && !this.users.length && <div className="MemberPromotionPage-inlineState" role="status"><i className="icon fas fa-search" />{this.t('users.no_results')}</div>}
        {this.selectedUser && this.userDetail()}
      </section>
    );
  }

  userDetail() {
    const user = this.selectedUser;
    return (
      <div className="MemberPromotionPage-userDetail">
        <div className="MemberPromotionPage-heading"><h3>#{user.userId} {user.username}</h3><div className="MemberPromotionPage-userActions">
          <Button className="Button" loading={this.userActionLoading === 'pause' || this.userActionLoading === 'resume'} disabled={!!this.userActionLoading} onclick={() => this.userAction(user.userId, user.state?.paused ? 'resume' : 'pause')}>{user.state?.paused ? this.t('users.resume') : this.t('users.pause')}</Button>
          <Button className="Button Button--primary" icon="fas fa-play" loading={this.userActionLoading === 'check'} disabled={!!this.userActionLoading} onclick={() => this.userAction(user.userId, 'check')}>{this.t('users.check_now')}</Button>
        </div></div>
        <p>{this.t('users.metrics', { replies: user.validReplies, minutes: user.ageMinutes })}</p>
        {!user.rules.length && <div className="MemberPromotionPage-inlineState"><i className="icon fas fa-info-circle" />{this.t('users.no_rules')}</div>}
        {user.rules.map((rule: any) => (
          <div className="MemberPromotionPage-ruleResult">
            <div><strong>{rule.name}</strong><small>{this.valueLabel('status', rule.status)} · {rule.reasons.map((reason: string) => this.valueLabel('reason', reason)).join(', ')}</small></div>
            <div>
              {(rule.status === 'completed' || rule.status === 'promoted') && <Button className="Button Button--danger" loading={this.userActionLoading === `revoke-${rule.ruleId}`} disabled={!!this.userActionLoading} onclick={() => this.userAction(user.userId, 'revoke', rule.ruleId)}>{this.t('users.revoke')}</Button>}
              {user.blocks.some((block: any) => Number(block.rule_id) === rule.ruleId) && <Button className="Button" loading={this.userActionLoading === `unblock-${rule.ruleId}`} disabled={!!this.userActionLoading} onclick={() => this.userAction(user.userId, 'unblock', rule.ruleId)}>{this.t('users.unblock')}</Button>}
            </div>
          </div>
        ))}
      </div>
    );
  }

  async searchUsers() {
    const query = this.userQuery.trim();
    if (!query || this.searchingUsers) return;
    this.searchingUsers = true;
    this.userSearched = true;
    this.users = [];
    this.error = '';
    try {
      const result: any = await app.request({ method: 'GET', url: this.api('/users'), params: { q: query } });
      this.users = result.users;
    } catch (error: any) {
      this.error = this.errorText(error);
    } finally {
      this.searchingUsers = false;
      m.redraw();
    }
  }

  async inspectUser(id: number) {
    if (this.inspectingUserId !== null) return;
    this.inspectingUserId = Number(id);
    this.error = '';
    try {
      this.selectedUser = await app.request({ method: 'GET', url: this.api(`/users/${id}`) });
      this.logFilters.user = String(id);
      await this.loadLogs(true);
    } catch (error: any) {
      this.error = this.errorText(error);
    } finally {
      this.inspectingUserId = null;
      m.redraw();
    }
  }

  async userAction(id: number, action: string, ruleId?: number) {
    if (this.userActionLoading) return;
    if (action === 'revoke' && !window.confirm(this.t('users.revoke_confirm') as string)) return;
    this.userActionLoading = ruleId ? `${action}-${ruleId}` : action;
    try {
      if (await this.request('POST', `/users/${id}/action`, { action, ruleId })) {
        await this.inspectUser(id);
        this.success(action === 'check' ? 'messages.user_checked' : 'messages.user_updated');
      }
    } finally {
      this.userActionLoading = '';
      m.redraw();
    }
  }

  logsView() {
    const hasFilters = Object.values(this.logFilters).some((value) => value !== '');

    return (
      <section className="MemberPromotionPage-section">
        <div className="MemberPromotionPage-heading MemberPromotionPage-heading--compact">
          <h2>{this.t('logs.title')}</h2>
          <Button className="Button Button--icon" icon="fas fa-sync-alt" aria-label={this.t('refresh') as string} title={this.t('refresh') as string} loading={this.loadingLogs} disabled={this.loadingLogs} onclick={() => this.loadLogs(true)} />
        </div>
        <form className="MemberPromotionPage-logFilters" onsubmit={(event: Event) => { event.preventDefault(); this.loadLogs(true); }}>
          <div className="MemberPromotionPage-logFiltersMain">
            <input name="member-promotion-log-user" aria-label={this.t('logs.user_filter') as string} className="FormControl" type="text" inputmode="numeric" pattern="[0-9]*" value={this.logFilters.user} placeholder={this.t('logs.user_filter') as string} oninput={(e: Event) => (this.logFilters.user = (e.target as HTMLInputElement).value)} />
            <select name="member-promotion-log-status" aria-label={this.t('logs.status') as string} className="FormControl" value={this.logFilters.status} onchange={(e: Event) => (this.logFilters.status = (e.target as HTMLSelectElement).value)}>
              <option value="">{this.t('logs.all_statuses')}</option>
              {['success', 'completed', 'skipped', 'failed'].map((status) => <option value={status}>{this.valueLabel('status', status)}</option>)}
            </select>
            <div className="MemberPromotionPage-filterActions">
              <Button type="submit" className="Button Button--primary" loading={this.loadingLogs} disabled={this.loadingLogs}>{this.t('logs.apply')}</Button>
              {hasFilters && <Button className="Button Button--link" disabled={this.loadingLogs} onclick={() => this.resetLogFilters()}>{this.t('logs.reset')}</Button>}
            </div>
          </div>
        </form>
        {this.loadingLogs && !this.logs.length ? <div className="MemberPromotionPage-loadingState" role="status" aria-label={this.t('logs.loading') as string}><LoadingIndicator /></div> : this.logs.length ? (
          <div className="MemberPromotionPage-tableWrap"><table className="MemberPromotionPage-table MemberPromotionPage-table--compact"><thead><tr><th>{this.t('logs.time')}</th><th>{this.t('logs.user')}</th><th>{this.t('logs.rule')}</th><th>{this.t('logs.status')}</th><th>{this.t('logs.groups')}</th></tr></thead><tbody>
            {this.logs.map((log) => <tr>
              <td>{log.created_at}<small>#{log.id}</small></td>
              <td>{log.user_id ? `#${log.user_id}` : '—'}</td>
              <td>{log.rule_id ? `#${log.rule_id}` : '—'}<small>{log.rule_version ? `v${log.rule_version}` : ''}</small></td>
              <td>{this.valueLabel('status', log.status)}{log.reason && <small>{this.reasonLabel(log.reason)}</small>}</td>
              <td>{log.added_group_id || log.removed_group_id ? `+${log.added_group_id || '—'} / -${log.removed_group_id || '—'}` : '—'}</td>
            </tr>)}
          </tbody></table></div>
        ) : <div className="MemberPromotionPage-emptyState MemberPromotionPage-emptyState--wide"><i className="icon fas fa-inbox" />{this.t('logs.empty')}</div>}
        {this.nextLog && <Button className="Button MemberPromotionPage-loadMore" loading={this.loadingLogs} disabled={this.loadingLogs} onclick={() => this.loadLogs(false)}>{this.t('logs.more')}</Button>}
      </section>
    );
  }

  async loadLogs(reset = true) {
    if (this.loadingLogs) return;
    this.loadingLogs = true;
    this.error = '';
    try {
      const filters = Object.fromEntries(Object.entries(this.logFilters).filter(([, value]) => value !== ''));
      const result: any = await app.request({ method: 'GET', url: this.api('/logs'), params: { ...filters, before: reset ? undefined : this.nextLog, limit: 50 } });
      this.logs = reset ? result.logs : [...this.logs, ...result.logs];
      this.nextLog = result.next;
    } catch (error: any) {
      this.error = this.errorText(error);
    } finally {
      this.loadingLogs = false;
      m.redraw();
    }
  }

  resetLogFilters() {
    this.logFilters = { user: '', status: '' };
    this.loadLogs(true);
  }

  statusView() {
    return (
      <section className="MemberPromotionPage-section">
        <div className="MemberPromotionPage-heading MemberPromotionPage-heading--compact">
          <h2>{this.t('status.title')}</h2>
          <Button className="Button Button--icon" icon="fas fa-sync-alt" aria-label={this.t('refresh') as string} title={this.t('refresh') as string} loading={this.loadingStatus} disabled={this.loadingStatus} onclick={() => this.loadStatus()} />
        </div>
        {this.loadingStatus && !this.status ? <div className="MemberPromotionPage-loadingState" role="status" aria-label={this.t('status.loading') as string}><LoadingIndicator /></div> : !this.status ? <div className="MemberPromotionPage-emptyState MemberPromotionPage-emptyState--wide"><i className="icon fas fa-exclamation-circle" />{this.t('status.unavailable')}</div> : <>
          <dl className="MemberPromotionPage-status">
            <div><dt>{this.t('status.heartbeat')}</dt><dd>{this.status.heartbeat || this.t('status.no_heartbeat')}</dd></div>
            <div><dt>{this.t('status.pending')}</dt><dd>{this.status.pendingUsers}</dd></div>
            <div><dt>{this.t('status.failed')}</dt><dd>{this.status.failedJobs}</dd></div>
          </dl>
          {this.status.lastError && <div className="MemberPromotionPage-statusError"><strong>{this.t('status.last_error')}</strong><span>{this.status.lastError}</span></div>}
          {this.status.jobs.length ? (
          <div className="MemberPromotionPage-tableWrap"><table className="MemberPromotionPage-table MemberPromotionPage-table--compact"><thead><tr><th>{this.t('status.type')}</th><th>{this.t('logs.status')}</th><th>{this.t('status.progress')}</th><th>{this.t('status.error')}</th></tr></thead><tbody>
            {this.status.jobs.map((job: any) => <tr><td>{this.valueLabel('job_type', job.type)}<small>#{job.id}</small></td><td>{this.valueLabel('status', job.status)}</td><td>{job.processed} / {job.total || '?'}</td><td>{job.last_error || '—'}</td></tr>)}
          </tbody></table></div>
          ) : <div className="MemberPromotionPage-emptyState MemberPromotionPage-emptyState--wide"><i className="icon fas fa-check-circle" />{this.t('status.no_jobs')}</div>}
        </>}
      </section>
    );
  }

  async loadStatus() {
    if (this.loadingStatus) return;
    this.loadingStatus = true;
    this.error = '';
    try {
      this.status = await app.request({ method: 'GET', url: this.api('/status') });
    } catch (error: any) {
      this.error = this.errorText(error);
    } finally {
      this.loadingStatus = false;
      m.redraw();
    }
  }

  async request(method: string, path: string, body: any): Promise<boolean> {
    if (this.saving) return false;
    this.saving = true;
    this.error = '';
    try {
      await app.request({ method, url: this.api(path), body });
      return true;
    } catch (error: any) {
      this.error = this.errorText(error);
      return false;
    } finally {
      this.saving = false;
      m.redraw();
    }
  }

  success(key: string, params: Record<string, any> = {}) {
    app.alerts.show({ type: 'success' }, this.t(key, params));
  }

  errorText(error: any) {
    const payload = error?.response?.data || error?.response;
    if (payload?.errors) return Object.values(payload.errors).join('；');
    return payload?.error || error?.message || String(error);
  }

  conditionText(rule: any) {
    const conditions = [];
    if (rule.age_enabled) {
      const isDays = rule.age_minutes % 1440 === 0;
      const value = isDays ? rule.age_minutes / 1440 : rule.age_minutes / 60;
      conditions.push(`${this.t('rules.age')} ${rule.age_operator} ${value} ${this.t(isDays ? 'rules.days' : 'rules.hours')}`);
    }
    if (rule.replies_enabled) conditions.push(`${this.t('rules.replies')} ${rule.replies_operator} ${rule.replies_count}`);
    return conditions.join(` ${this.t('rules.and')} `);
  }

  reasonLabel(value: string) {
    return String(value || '—').split(',').map((reason) => this.valueLabel('reason', reason.trim())).join(', ');
  }

  valueLabel(type: 'status' | 'reason' | 'job_type', value: string) {
    if (!value) return '—';
    const key = `dunea-member-promotion.admin.values.${type}.${value}`;
    const translated = app.translator.trans(key);
    return String(translated) === key ? value : translated;
  }

  groupName(id: any) {
    return this.data.groups.find((group: any) => Number(group.id) === Number(id))?.name_singular;
  }

  groupSelect(key: string, value: any, optional: boolean, change: (value: any) => void) {
    const id = this.fieldId(key);
    return <div className="Form-group"><label for={id}>{this.t(key)}</label><select id={id} name={id} className="FormControl" required={!optional} value={value} onchange={(e: Event) => change((e.target as HTMLSelectElement).value)}>{optional ? <option value="">{this.t('rules.none')}</option> : <option value="" disabled>{this.t('rules.select_group')}</option>}{this.data.groups.map((group: any) => <option value={group.id}>{group.name_singular}</option>)}</select></div>;
  }

  textField(key: string, value: string, change: (value: string) => void, required = false, placeholderKey?: string) {
    const id = this.fieldId(key);
    return <div className="Form-group"><label for={id}>{this.t(key)}</label><input id={id} name={id} className="FormControl" required={required} value={value} placeholder={placeholderKey ? this.t(placeholderKey) as string : undefined} oninput={(e: Event) => change((e.target as HTMLInputElement).value)} /></div>;
  }

  numberField(key: string, value: number, min: number, max: number, change: (value: number) => void) {
    const id = this.fieldId(key);
    return <div className="Form-group"><label for={id}>{this.t(key)}</label><input id={id} name={id} className="FormControl" type="number" min={min} max={max} step="1" value={value} oninput={(e: Event) => change(Number((e.target as HTMLInputElement).value))} /></div>;
  }

  fieldId(key: string) {
    return `member-promotion-${key.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`;
  }

  t(key: string, params: Record<string, any> = {}) {
    return app.translator.trans(`dunea-member-promotion.admin.${key}`, params);
  }

  plainText(key: string, params: Record<string, any> = {}) {
    const flatten = (value: any): string => Array.isArray(value) ? value.map(flatten).join('') : (value == null ? '' : String(value));

    return flatten(this.t(key, params));
  }
}

interface RuleModalAttrs extends IInternalModalAttrs {
  page: MemberPromotionPage;
  rule: any;
}

class RuleModal extends Modal<RuleModalAttrs> {
  rule: any;
  error = '';

  oninit(vnode: Mithril.Vnode<RuleModalAttrs, this>) {
    super.oninit(vnode);
    this.rule = { ...this.attrs.rule };
  }

  className() {
    return 'MemberPromotionRuleModal Modal--large';
  }

  title() {
    return this.attrs.page.t(this.rule.id ? 'rules.edit_title' : 'rules.new');
  }

  content() {
    return (
      <div className="Modal-body">
        {this.error && <div className="Alert Alert--error MemberPromotionRuleModal-error">{this.error}</div>}
        {this.attrs.page.ruleFields(this.rule, this.loading, () => this.hide())}
      </div>
    );
  }

  onready() {
    if (window.innerWidth <= 760) {
      const manager = this.$().closest('.ModalManager').get(0);
      if (manager) manager.scrollTop = 0;
      return;
    }
    super.onready();
  }

  onsubmit(event: SubmitEvent) {
    event.preventDefault();
    this.save();
  }

  async save(confirmStructural = false): Promise<void> {
    if (this.loading) return;
    const page = this.attrs.page;
    const path = this.rule.id ? `/rules/${this.rule.id}` : '/rules';
    this.error = '';
    this.loading = true;

    try {
      await app.request({ method: this.rule.id ? 'PATCH' : 'POST', url: page.api(path), body: { ...this.rule, confirmStructural } });
      await page.load();
      this.hide();
      page.success('messages.rule_saved');
    } catch (error: any) {
      const status = error?.status || error?.response?.status;
      if (status === 409 && !confirmStructural && window.confirm(page.t('rules.structural_confirm') as string)) {
        this.loading = false;
        await this.save(true);
        return;
      }
      this.error = page.errorText(error);
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
}
