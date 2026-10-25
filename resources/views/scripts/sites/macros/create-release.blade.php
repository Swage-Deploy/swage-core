@if ($site->zero_downtime)
echo "Cloning {!! $site->repository !!} ({!! $site->branch !!}) into release $SWAGE_RELEASE_NAME"
git clone --depth 1 --branch "$SWAGE_SITE_BRANCH" '{!! $site->cloneUrl() !!}' "$SWAGE_RELEASE_DIRECTORY"
git -C "$SWAGE_RELEASE_DIRECTORY" log -1 --format='SWAGE_COMMIT %H%nSWAGE_AUTHOR %an%nSWAGE_MESSAGE %s'
export SWAGE_COMMIT_HASH="$(git -C "$SWAGE_RELEASE_DIRECTORY" rev-parse HEAD)"
rm -rf "$SWAGE_RELEASE_DIRECTORY/storage"
ln -s "$SWAGE_SITE_PATH/shared/storage" "$SWAGE_RELEASE_DIRECTORY/storage"
ln -sfn "$SWAGE_SITE_PATH/shared/.env" "$SWAGE_RELEASE_DIRECTORY/.env"
@else
if [ -L "$SWAGE_RELEASE_DIRECTORY" ]; then
    # The first deployment replaces the placeholder page with a checkout of the repository.
    echo "Cloning {!! $site->repository !!} ({!! $site->branch !!}) into $SWAGE_RELEASE_DIRECTORY"
    rm "$SWAGE_RELEASE_DIRECTORY"
    rm -rf "$SWAGE_SITE_PATH/releases"
    git clone --depth 1 --branch "$SWAGE_SITE_BRANCH" '{!! $site->cloneUrl() !!}' "$SWAGE_RELEASE_DIRECTORY"
else
    echo "Pulling {!! $site->repository !!} ({!! $site->branch !!}) into $SWAGE_RELEASE_DIRECTORY"
    git -C "$SWAGE_RELEASE_DIRECTORY" fetch --depth 1 origin "$SWAGE_SITE_BRANCH"
    git -C "$SWAGE_RELEASE_DIRECTORY" reset --hard FETCH_HEAD
fi
git -C "$SWAGE_RELEASE_DIRECTORY" log -1 --format='SWAGE_COMMIT %H%nSWAGE_AUTHOR %an%nSWAGE_MESSAGE %s'
export SWAGE_COMMIT_HASH="$(git -C "$SWAGE_RELEASE_DIRECTORY" rev-parse HEAD)"
ln -sfn "$SWAGE_SITE_PATH/shared/.env" "$SWAGE_RELEASE_DIRECTORY/.env"
@endif
