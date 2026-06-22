# Rezervační systém

WordPress plugin pro skautchlumec.cz.

## Deploy

Automatický deploy přes GitHub Actions → webhook na serveru.

**Manuální prvotní nastavení:**
1. Stáhnout `deploy-webhook.php` z GitHubu a nahrát přes webFTP na server do `/public_html/wp-content/plugins/rezervacni-system/`
2. V souboru změnit `CHANGE_ME` na náhodný token
3. Přidat token jako GitHub Secret `DEPLOY_SECRET` v Settings → Secrets → Actions
4. Ověřit: `https://skautchlumec.cz/wp-content/plugins/rezervacni-system/deploy-webhook.php?token=TOKEN`

## Shortcodes

```
[rs_admin]      – administrace (pro přihlášené s rolí)
[rs_kalendar]   – veřejný kalendář obsazenosti
[rs_formular]   – rezervační formulář pro veřejnost
```
