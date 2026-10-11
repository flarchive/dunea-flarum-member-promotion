import app from 'flarum/admin/app';
import MemberPromotionPage from './components/MemberPromotionPage';

app.initializers.add('dunea-member-promotion', () => {
  app.extensionData.for('dunea-member-promotion').registerPage(MemberPromotionPage);
});
