@if ($site->zero_downtime)
echo "Activating release $SWAGE_RELEASE_NAME"
ln -sfn "$SWAGE_RELEASE_DIRECTORY" "$SWAGE_SITE_PATH/current-next"
mv -Tf "$SWAGE_SITE_PATH/current-next" "$SWAGE_SITE_PATH/current"
export SWAGE_RELEASE_ACTIVATED=1
( flock -w 10 9 || exit 1; swage_reload_php ) 9> "/tmp/swage-fpm-$(id -un).lock"
ls -1dt "$SWAGE_SITE_PATH"/releases/*/ | tail -n +{!! config('swage.releases_to_keep') + 1 !!} | xargs -r rm -rf
@elseif ($site->octane)
echo "Gracefully reloading Octane workers"
php "$SWAGE_RELEASE_DIRECTORY/artisan" octane:reload
@else
echo "Reloading PHP-FPM"
( flock -w 10 9 || exit 1; swage_reload_php ) 9> "/tmp/swage-fpm-$(id -un).lock"
@endif
