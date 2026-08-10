# Vydávanie a nasadzovanie

Plugin nie je na wordpress.org. Distribuuje sa z verejného repozitára
[pixeler-sk/px-shop-core](https://github.com/pixeler-sk/px-shop-core) a na
klientskych weboch sa aktualizuje cez bežnú WordPress aktualizáciu.

## Ako to funguje

```
  git tag v1.0.1 → push
        │
        ▼
  GitHub Action (.github/workflows/release.yml)
        ├─ overí, že verzia sedí na všetkých 3 miestach
        ├─ zostaví px-shop-core-1.0.1.zip (bez vývojárskych súborov)
        └─ vytvorí GitHub release + priloží zip
        │
        ▼
  klientsky web: Plugin Update Checker sa raz za 12 h spýta GitHub API
        └─ nájde novšiu verziu → Nástenka → Aktualizácie
```

Na klientskych weboch nie sú žiadne tokeny ani prístupové údaje — repozitár je
verejný.

## Postup

1. **Zmeň verziu na troch miestach** (musia sedieť, CI to kontroluje):

   | Súbor | Riadok |
   |---|---|
   | `px-shop-core.php` | `* Version: 1.0.1` (hlavička) |
   | `px-shop-core.php` | `define( 'PX_SHOP_CORE_VERSION', '1.0.1' );` |
   | `readme.txt` | `Stable tag: 1.0.1` |

2. **Doplň changelog** do `readme.txt` — sekcia musí byť presne `= 1.0.1 =`,
   inak CI zlyhá. Text sekcie sa stane popisom releasu na GitHube aj
   changelogom v okne „Zobraziť podrobnosti" vo WordPresse.

3. **Commit, tag, push:**

   ```
   git commit -am "Popis zmeny"
   git push
   git tag v1.0.1
   git push origin v1.0.1
   ```

4. CI zostaví zip a vytvorí release. Weby s nainštalovaným pluginom ponúknu
   aktualizáciu do 12 hodín (alebo hneď cez Nástenka → Aktualizácie →
   Skontrolovať znova).

## Prvé nasadenie na web

Stiahni zip z [releases](https://github.com/pixeler-sk/px-shop-core/releases)
a nahraj cez Pluginy → Inštalovať nový → Nahrať plugin. Ďalšie aktualizácie už
chodia samé.

Pozn.: benab má zatiaľ pracovnú kópiu vo vlastnom repe (bez PUC) — pri najbližšej
príležitosti ju nahradiť inštaláciou z releasu, nech je zdroj jeden.
