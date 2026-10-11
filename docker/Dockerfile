ARG FLARUM_IMAGE=crazymax/flarum:1.8.19
FROM ${FLARUM_IMAGE}

COPY --chown=flarum:flarum . /opt/member-promotion

RUN composer config --working-dir=/opt/flarum \
      repositories.member-promotion path /opt/member-promotion \
 && composer require --working-dir=/opt/flarum \
      dunea/flarum-member-promotion:@dev \
      flarum-lang/chinese-simplified:^1.6 \
      --no-interaction --prefer-dist
