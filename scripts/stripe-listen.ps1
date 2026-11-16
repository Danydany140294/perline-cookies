<#
.SYNOPSIS
  Lance « stripe listen » pour le développement local ET synchronise le secret du webhook.

.DESCRIPTION
  - lit STRIPE_SECRET_KEY dans .env.local puis .env (sans l'afficher, et sans la passer en ligne de commande :
    elle est transmise au CLI par la variable d'environnement STRIPE_API_KEY) ;
  - écrit le secret de signature du webhook annoncé par le CLI dans .env.local (UNIQUE source locale de
    STRIPE_WEBHOOK_SECRET), pour qu'il ne puisse plus diverger de celui que Stripe utilise réellement ;
  - transmet ensuite les événements à l'application (http://127.0.0.1:8000/webhook/stripe).

  Usage :   powershell -File scripts/stripe-listen.ps1            (lance l'écoute, à laisser ouverte)
            powershell -File scripts/stripe-listen.ps1 -SecretSeulement   (synchronise le secret puis s'arrête)
#>
param([switch]$SecretSeulement, [string]$Url = 'http://127.0.0.1:8000/webhook/stripe')

$racine = Split-Path -Parent $PSScriptRoot
Set-Location $racine
$cli = Join-Path $racine 'stripe.exe'
if (-not (Test-Path $cli)) { $cli = (Get-Command stripe -ErrorAction Stop).Source }

function Lire-Variable([string]$nom) {
    foreach ($f in '.env.local', '.env') {
        if (Test-Path $f) {
            $l = Get-Content $f | Where-Object { $_ -like "$nom=*" } | Select-Object -Last 1
            if ($l) { return (($l -split '=', 2)[1]).Trim().Trim('"', "'") }
        }
    }
    return $null
}

$cle = Lire-Variable 'STRIPE_SECRET_KEY'
if (-not $cle -or -not $cle.StartsWith('sk_test_')) { Write-Error 'STRIPE_SECRET_KEY (clé de TEST sk_test_...) introuvable dans .env.local / .env.'; exit 1 }
$env:STRIPE_API_KEY = $cle

$secret = (& $cli listen --print-secret 2>$null | Out-String).Trim()
if (-not $secret.StartsWith('whsec_')) { Write-Error 'Impossible d''obtenir le secret du webhook auprès du CLI Stripe.'; exit 1 }

$lignes = @()
if (Test-Path '.env.local') { $lignes = @(Get-Content '.env.local' | Where-Object { $_ -notlike 'STRIPE_WEBHOOK_SECRET=*' }) }
$lignes += "STRIPE_WEBHOOK_SECRET=$secret"
# Écriture SANS BOM : Symfony (Dotenv) refuse un fichier .env qui commence par un BOM
[IO.File]::WriteAllLines((Join-Path $racine '.env.local'), [string[]]$lignes, (New-Object System.Text.UTF8Encoding $false))
Write-Host "Secret du webhook synchronisé dans .env.local ($($secret.Length) caractères, non affiché)."
if ($SecretSeulement) { exit 0 }

Write-Host "Écoute Stripe -> $Url (Ctrl+C pour arrêter)"
& $cli listen --forward-to $Url --events 'checkout.session.completed,checkout.session.expired,charge.refunded'
