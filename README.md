# Webform Strawberry Field
A module that provides Drupal 8/9 Webform ( == awesome piece of code) integrations for StrawberryField so you can really have control over your Metadata ingests. This is part of the Archipelago Commons Project.

# Setup

This module provides many LoD Autocomplete suggester Webform Elements, but only *The Europeana Entity Suggester* for now requires you to provide an `APIKEY`.
To be able to use the Europeana Suggester edit your Drupal `settings.php` file (located normally in `web/sites/default/settings.php`) and add the following line:

```PHP
$settings['webform_strawberryfield.europeana_entity_apikey'] = 'thekey';
```

Also, nominatim (Georeference from open Streetmaps) requires a valid/non generic User Agent to be passed. The default value, when not set
is `Archipelago Commons Repository/1.x at info@metro.org`. Same as with europeana, edit your Drupal `settings.php` file and set one based on the example. 
We encourage you to please edit this and not run in production with the default one, to avoid blocking others in the future it you exceed your requests.

```PHP
$settings['webform_strawberryfield.nominatim_user_agent'] = "Archipelago Commons Repository/1.5 at your@email / sitename";
```

Save and clear caches.

In its current state the Europeana Entity API (Alpha 0.10.3) as of December 2021 uses a static APIKEY (not the same as other APIs) and can be requested at https://pro.europeana.eu/page/get-api

If using https://github.com/esmero/archipelago-deployment this is not needed and a stub one be provided by the deployment.
Please read the Terms of Use: https://www.europeana.eu/en/rights/api-terms-of-use

## Help

Having issues with this module? Check out the Archipelago Commons google groups for tech + emotional support + updates.

* [Archipelago Commons](https://groups.google.com/forum/#!forum/archipelago-commons)

## Demo

* archipelago.nyc (http://archipelago.nyc)

## Caring & Coding + Fixing

* [Diego Pino](https://github.com/DiegoPino)
* [Giancarlo Birello](https://github.com/giancarlobi)
* [Allison Lund](https://github.com/alliomeria)

## Acknowledgments

This software is a [Metropolitan New York Library Council](https://metro.org) Open-Source initiative and part of the Archipelago Commons project.

## License

[GPLv3](http://www.gnu.org/licenses/gpl-3.0.txt)

