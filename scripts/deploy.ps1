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
  ./ "$SshUser@$SshHost:$remoteRelease/"

Write-Host "Running remote deployment steps..."
$remoteCmd = "cd '$remoteRelease/backend' && composer install --no-dev --optimize-autoloader && ln -sfn '$remoteSharedEnv' .env && php vendor/bin/phinx migrate -e production && php bin/console create:superadmin && ln -sfn '$remoteRelease' '$remoteCurrent'"
ssh -p $SshPort "$SshUser@$SshHost" $remoteCmd

Write-Host "Deployment finished. Run health check manually:"
Write-Host "curl -f https://site-name.ru/health"
