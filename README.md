# Aplikace pro rezervaci prostor skautského střediska

WordPress plugin pro rezervaci prostor, nasazený nna skautchlumec.cz.

Aplikace slouží k rezervaci objektů a jejich částí (místnosti, chatky). Umožňuje přijímat žádosti od veřejnosti i plánovat vlastní interní akce oddílu – vše na jednom místě.

---

## Co vidí veřejnost (nepřihlášení návštěvníci)

- **Kalendář obsazenosti** – přehledný měsíční kalendář zobrazující, kdy jsou objekty zcela nebo částečně obsazené a kdy volné. Veřejnost nevidí detaily rezervací; odlišeny jsou potvrzené a čekající termíny.
- **Formulář pro poptávku prostor** – návštěvník vyplní kontaktní údaje, zvolí termín (aplikace kontroluje dostupnost prostor), objekt a počet osob, a odešle žádost čekající na schválení správcem.
  - Po odeslání přijde žadateli e-mail s potvrzením a **jedinečným odkazem pro správu rezervace**. Přes něj může sledovat stav; osobu žadatele ani termín již měnit nelze.
  - Po potvrzení nebo zamítnutí přijde automatický e-mail s výsledkem a automaticky spočítanou cenou.
  - Aplikace je připravena pro vybírání "vzdušného" (ubytovacího poplatku obci). Je-li zapnuto **vzdušné (ubytovací poplatek)**, je žadatel po potvrzení vyzván k vyplnění jmen, dat narození a bydliště ubytovaných. Upomínky k vyplnění se automaticky odesílají 7 dní a 1 den před pobytem.

---

## Vedení oddílů (role Author a výše)

Přístup k záložce **Interní rezervace**:

- Zadat interní akci (výprava, schůzka, přespávačka…) přímo bez schvalovacího procesu.
- Nastavit opakující se rezervaci (typicky schůzky na celou sezónu) jedním kliknutím – lze zvolit **více dní v týdnu najednou** (např. pondělí a středa) a nastavit **interval opakování** (každý týden, ob týden, každý třetí týden…). Systém automaticky přeskočí státní svátky a prázdniny (lze vypnout). Série se v přehledu zobrazí sbalená pod jedno označení.
- Zrušit jednotlivé termíny nebo celou sérii – každý vedoucí může rušit jen rezervace, které sám vytvořil.

---

## Správce rezervací

Záložka **Správa rezervací**:

- Vidí všechny rezervace rozdělené na **Externí** (veřejnost) a **Interní** (oddíl).
- Může rezervaci **potvrdit** nebo **zamítnout** – žadatel je informován automatickým e-mailem.
- Cena je automaticky vypočtena podle ceníku (s respektováním nastavené minimální ceny); správce ji může individuálně upravit v detailu rezervace.
- Může zobrazit detail, upravit nebo zrušit jakoukoli rezervaci.

---

## Administrátor systému

- **Typy objektů** – definuje kategorie (skautský dům, příměstský areál, tábořiště…).
- **Objekty** – spravuje pronajímatelné prostory včetně popisu, fotografií, GPS souřadnic (proklik na mapy.cz) a přiřazení k typu. Objekt lze dočasně vypnout z nabídky pro veřejnost na dané rozmezí nebo do odvolání.
- **Ceník pronájmu objektů** – ceny za nocleh a pobyt. Při zapnutém vzdušném se nastaví sazby obecního poplatku podle věkových kategorií; podporuje kategorii neplatících (např. dospělý doprovod dle vyhlášky).
- **Prázdniny & Svátky** – seznam dat, která systém automaticky přeskakuje při tvorbě opakujících se rezervací.
- **Nastavení** – parametry notifikačních e-mailů a další konfigurace.

---

## Shortcodes

```
[rs_admin]      – administrace (pro přihlášené s příslušnou rolí)
[rs_kalendar]   – veřejný kalendář obsazenosti
[rs_formular]   – rezervační formulář pro veřejnost
```

---

## Deploy

Automatický deploy přes GitHub Actions → webhook na serveru.

**Manuální prvotní nastavení:**
1. Stáhnout `deploy-webhook.php` z GitHubu a nahrát přes webFTP na server do `/public_html/wp-content/plugins/rezervacni-system/`
2. V souboru změnit `CHANGE_ME` na náhodný token
3. Přidat token jako GitHub Secret `DEPLOY_SECRET` v Settings → Secrets → Actions
4. Ověřit: `https://skautchlumec.cz/wp-content/plugins/rezervacni-system/deploy-webhook.php?token=TOKEN`
