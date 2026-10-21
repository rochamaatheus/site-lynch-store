$ErrorActionPreference = 'Stop'
Push-Location $PSScriptRoot
try {
    Set-Location ..
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao preparar dependências PHP.' }
    php scripts/build.php @args
    if ($LASTEXITCODE -ne 0) { throw 'Falha no build.' }
} finally {
    Pop-Location
}
