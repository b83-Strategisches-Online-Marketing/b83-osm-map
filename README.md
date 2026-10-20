# b83 OpenStreetMap

OpenStreetMap-Karten für WordPress per Shortcode – mit einer oder mehreren Adressen, ansprechenden Kartenstilen, formatierten Info-Fenstern, Pin-Icons und frei wählbarem Design. Ein Plugin von [b83 – Strategisches Online Marketing](https://b83.at).

- Leaflet 1.9.4 und alle Icons liegen **lokal** im Plugin – kein CDN, kein Google
- Karten im Backend verwalten (**b83 → OpenStreetMap**) mit Live-Vorschau, oder direkt per Shortcode
- Adresse eingeben → Koordinaten werden automatisch ermittelt (Nominatim) und gespeichert
- 7 Kartenstile + 5 Farbfilter, wahlweise Stilwechsel für Besucher
- Pins: Tropfen, Kreis, Quadrat, nur Icon oder eigenes Bild; 57 Icons (Font Awesome Free) oder eigene Font-Awesome-Klasse
- Info-Fenster: Titel, Adresse, formatierter Text, Bild, Telefon, E-Mail (Spam-geschützt), Website, Routen-Link
- Design: Farben, Höhe (Desktop/Handy), Rahmen, Eckenrundung, Schatten
- Datenschutz: „Karte erst nach Klick laden“ (Zwei-Klick-Lösung) mit „immer laden“-Option
- Lädt Dateien nur auf Seiten mit Karte, Kacheln erst beim Scrollen in die Nähe
- Bedienung erst nach Klick (Seite bleibt scrollbar), Adressliste unter der Karte, Vollbild
- Funktioniert in Block-Editor, Elementor (inkl. Editor-Vorschau und Popups), Tabs und Akkordeons

Voraussetzungen: WordPress 6.2+, PHP 8.1+.

## Shortcodes

```text
[b83_osm id="1"]
```
Gespeicherte Karte. Alle Design-Attribute (siehe unten) überschreiben die Einstellungen der Karte.

```text
[b83_osm address="Markt 165, 5441 Abtenau" title="Tourismusverband Abtenau" icon="landmark"]
<strong>Öffnungszeiten</strong><br>Mo–Fr 8–12 Uhr
[/b83_osm]
```
Eine Adresse direkt. Text zwischen den Tags erscheint formatiert im Info-Fenster. Statt `address` ist `lat`/`lng` genauer und schneller.

```text
[b83_osm list="below" zoom="14"]
[b83_osm_marker title="Hotel Post" address="Markt 1, 5441 Abtenau" icon="hotel"]Rezeption 0–24 Uhr[/b83_osm_marker]
[b83_osm_marker title="Parkplatz" lat="47.5632" lng="13.3458" icon="square-parking" color="#2563eb"]
[/b83_osm]
```
Mehrere Adressen; die Karte passt den Ausschnitt automatisch an.

**Hinweis:** Stehen im selben Textblock mehrere Karten, kurze Shortcodes mit ` /]` schließen (`[b83_osm id="1" /]`) – sonst liest WordPress alles bis zum nächsten `[/b83_osm]` als Inhalt. In Elementor und im Block-Editor (je ein Shortcode-Widget/-Block) ist das kein Thema.

### Attribute einer Adresse
`title`, `address`, `lat`, `lng`, `phone`, `email`, `website`, `icon`, `color` (nur `[b83_osm_marker]`), `image` (Mediathek-ID). In `[b83_osm]` gilt `icon` für alle Adressen.

### Design-Attribute
| Attribut | Werte |
|---|---|
| `style` | `osm`, `osm-de`, `carto-voyager`, `carto-light`, `carto-dark`, `topo`, `satellite` |
| `filter` | `none`, `grayscale`, `muted`, `warm`, `dark` |
| `layer_switch`, `zoom_buttons`, `fullscreen`, `consent` | `1` / `0` |
| `zoom` | 1–19 |
| `scroll` | `click`, `always`, `never` |
| `list` | `none`, `below` |
| `height`, `height_mobile` | 150–1200 (px) |
| `radius`, `border_width` | px |
| `border_color`, `color`, `icon_color`, `popup_bg`, `popup_text` | `#rrggbb` |
| `shadow` | `none`, `soft`, `strong` |
| `pin_style` | `drop`, `circle`, `square`, `icon`, `image` |
| `pin_icon` | Icon-Name, `dot`, `none` oder z. B. `fa-solid fa-hotel` |
| `pin_size` | 20–72 |
| `pin_image` | Mediathek-ID |
| `popup_radius` | 0–30 |
| `popup_open` | `first`, `none` |
| `route` | `osm`, `google`, `none` |
| `route_label`, `consent_text` | Text |
| `class` | eigene CSS-Klasse |

Die vollständige Liste mit Icons steht im Backend unter **b83 → OpenStreetMap → Shortcodes & Hilfe**.

## Kartenstile und Nutzungsbedingungen
| Stil | Anbieter | Hinweis |
|---|---|---|
| OpenStreetMap Standard / Deutschland | OSMF / FOSSGIS | für normalen Website-Verkehr gedacht ([Tile Usage Policy](https://operations.osmfoundation.org/policies/tiles/)) |
| CARTO Voyager / Positron / Dark Matter | CARTO | kostenlos nur eingeschränkt – bei gewerblicher Nutzung mit viel Verkehr Bedingungen prüfen |
| OpenTopoMap | OpenTopoMap | ehrenamtlich betrieben, ideal für Wander-/Bergkarten |
| Satellit | Esri | Nutzungsbedingungen von Esri beachten |

Die **Farbfilter** (Graustufen, Gedämpft, Warm, Dunkel) arbeiten mit CSS auf den OSM-Kacheln und brauchen keinen weiteren Anbieter.

## Datenschutz
Leaflet, CSS, JavaScript und Icons liegen auf der eigenen Website. Die Kartenkacheln lädt der Browser vom gewählten Anbieter – dabei wird die IP-Adresse übertragen. Den Anbieter in der Datenschutzerklärung nennen oder **„Karte erst nach Klick laden“** aktivieren. Die Adresssuche (Nominatim) läuft ausschließlich über den Server der Website, nie über die Besucher, und jede Adresse wird nur einmal abgefragt.

## Für Entwickler

### Filter
| Filter | Zweck |
|---|---|
| `b83_osm_layers` | Kartenstile ergänzen/entfernen (`label`, `url`, `attribution`, `maxZoom`, `subdomains`) |
| `b83_osm_icons` | eigene SVG-Icons (`label`, `viewbox`, `path`) |
| `b83_osm_allowed_html` | erlaubtes HTML im Info-Fenster |
| `b83_osm_map_config` | Konfiguration einer Karte für das Frontend-Skript |
| `b83_osm_popup_html` | HTML eines Info-Fensters |
| `b83_osm_render` | fertiges HTML einer Karte |
| `b83_osm_geocode_url` | anderer Nominatim-kompatibler Geocoding-Dienst |
| `b83_osm_enqueue_early` | CSS im `<head>` laden (Karte in Widget/Template) |
| `b83_osm_capability` | Berechtigung für die Verwaltung (Standard `manage_options`) |
| `b83_osm_purge_post_ids` | Seiten, deren Cache nach dem Speichern geleert wird |

### Actions
`b83_osm_loaded`, `b83_osm_settings_saved`, `b83_osm_map_saved`, `b83_osm_map_deleted`, `b83_osm_purge_cache`

### JavaScript
```js
window.b83Osm.init( element );  // Karten in nachgeladenem Inhalt aufbauen
window.b83Osm.load( element );  // Karte mit Zwei-Klick-Lösung sofort laden (z. B. nach Cookie-Zustimmung)
document.addEventListener( 'b83-osm:ready', e => { e.detail.map /* Leaflet-Karte */ } );
```

### Daten
Optionen `b83_osm_settings`, `b83_osm_maps`, `b83_osm_next_id`, `b83_osm_db_version`, `b83_osm_geocache`. Gelöscht wird beim Deinstallieren nur, wenn das unter „Standard-Design → Allgemein“ aktiviert ist.

## Updates
Updates kommen über GitHub-Releases (`Update URI`). Ablauf: Version in Header, `B83_OSM_VERSION` und `CHANGELOG.md` erhöhen, auf `main` pushen – die GitHub Action erstellt das Release mit `b83-osm-map.zip`.

## Lizenz
GPL-2.0-or-later. Enthält Leaflet (BSD-2-Clause, `assets/vendor/leaflet/LICENSE`) und Icons aus Font Awesome Free (CC BY 4.0, https://fontawesome.com/license/free).
