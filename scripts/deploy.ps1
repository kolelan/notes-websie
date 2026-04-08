param(
    [Parameter(Mandatory = $true)][string]$SshHost,
    [Parameter(Mandatory = $true)][string]$SshUser,
    [int]$SshPort = 22
)

$ErrorActionPreference = "Stop"

$remoteBase = "/var/www/site-name.ru"
$releaseName = Get-Date -Format "yyyyMMddHHmmss"
$remoteReleases = "$remoteBase/releases"
$remoteRelease = "$remoteReleases/$releaseName"
$remoteCurrent = "$remoteBase/current"
$remoteSharedEnv = "$remoteBase/shared/.env"

Write-Host "Creating release directory on server..."
ssh -p $SshPort "$SshUser@$SshHost" "mkdir -p '$remoteRelease' '$remoteReleases' '$remoteBase/shared'"

Write-Host "Uploading project files..."
rsync -az --delete `
  --exclude ".git" `
  --exclude ".idea" `
  --exclude "backend/vendor" `
  -e "ssh -p $SshPort" `
  ./ "$SshUser@${SshHost}:$remoteRelease/"

Write-Host "Running remote deployment steps..."
$remoteCmd = "cd '$remoteRelease/backend' && composer install --no-dev --optimize-autoloader && ln -sfn '$remoteSharedEnv' .env && grep -q '^REDIS_HOST=' .env && grep -q '^REDIS_PORT=' .env && php vendor/bin/phinx migrate -e production && php bin/console create:superadmin && ln -sfn '$remoteRelease' '$remoteCurrent'"
ssh -p $SshPort "$SshUser@$SshHost" $remoteCmd

Write-Host "Deployment finished. Run health check manually:"
Write-Host "curl -f https://site-name.ru/health"
Write-Host "Also verify Redis connectivity from app server:"
Write-Host "php -r 'require ""vendor/autoload.php""; require ""src/Config/bootstrap.php""; `$c = App\\Cache\\RedisFactory::createFromEnv(); var_dump(`$c->ping());'"
