#!/usr/bin/env bash
#
# نشر الخادم — كل ما يجب أن يحدث بعد وصول كود جديد إلى الإنتاج.
#
# يعمل تلقائياً بعد كل `git pull` / `git merge` عبر hook (‎.git/hooks/post-merge)،
# ويمكن تشغيله يدوياً: `bash backend/deploy.sh`.
#
# سبب وجوده: Laravel يقرأ الراوترات من `bootstrap/cache/routes-*.php` متى وُجد الملف،
# فإن أُضيف راوت جديد ولم يُعَد بناء الكاش، يردّ الخادم 404 على راوت موجود في الكود.
set -euo pipefail

cd "$(dirname "$0")"

php_bin=${PHP_BIN:-php}

# Composer فقط عند تغيّر القفل — لا داعي لدقيقة انتظار في كل نشر.
lock_stamp="storage/framework/.composer.lock.md5"
lock_now=$(md5sum composer.lock | cut -d' ' -f1)
if [[ ! -f "$lock_stamp" || "$(cat "$lock_stamp")" != "$lock_now" ]]; then
    echo "==> composer install"
    composer install --no-dev --optimize-autoloader --no-interaction
    echo "$lock_now" > "$lock_stamp"
fi

echo "==> migrate"
"$php_bin" artisan migrate --force

# كل الصلاحيات المعرَّفة في PermissionName يجب أن توجد في قاعدة البيانات، وإلا ردّ
# الخادم 403 على راوت صلاحيته جديدة. إضافة فقط — لا تمسّ توزيع الصلاحيات على الأدوار.
echo "==> permissions:sync"
"$php_bin" artisan permissions:sync

# `optimize` وحده يكفي: كل من config/route/view:cache يمسح نسخته القديمة قبل الكتابة.
# لا نستدعي `optimize:clear` لأنه يمسح معه كاش التطبيق (CACHE_STORE=database) بلا داعٍ.
echo "==> optimize (config + routes + views + events)"
"$php_bin" artisan optimize

"$php_bin" artisan queue:restart >/dev/null 2>&1 || true

echo "==> done"
