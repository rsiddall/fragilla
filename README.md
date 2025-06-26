# fragilla

fragilla is a simple YAML-controlled web scraper written in PHP.  It uses [chrome-php](https://github.com/chrome-php/chrome) to control a [Chromium](https://www.chromium.org/Home/)-based browser to load pages and [Twig](https://twig.symfony.com/doc/3.x/) to expand strings in the YAML.

The YAML file contains a list of jobs that the scraper will perform.  It can extract single HTML attributes, an array of HTML attributes from the same HTML file, and the text content of specific elements in a page.

The scraper will save a copy of each page it scrapes.

The usual run is:

- Load the search page and pull out attributes for limiting the search

- Run one or more searches to build a list of detail page URLs

- Fetch all the detail pages and pull out interesting elements

- Save or display the results as JSON or CSV

At this time all attribute and element locators are XPath expressions.

Data is saved into the YAML array.

## Installation

Ensure you have PHP, [composer](https://getcomposer.org/) and a Chromium-based browser installed, clone this repository, then run

```
composer update
```

to download the prerequisites.

## Use

```
php fragilla.php -o results.csv myscrape.yaml
```

The following operations are supported:

| Operation | Key | Description |
| --------- | --- | ----------- |
| dump      |     | Dump the YAML to the screen |
| message   |     | Display a message on the screen |
|           | message | Message to display |
| attribute |     | Extract a single attribute from HTML |
|           | url | The URL of the page to fetch |
|           | file | The file to save a copy of the HTML into |
|           | xpath | XPath expression for the element to find |
|           | attribute | Attribute of the selected element to save |
|           | into | YAML key to save the attribute to |
| attributes |     | Extract all values of matching attribute from HTML |
|           | url | The URL of the page to fetch |
|           | file | The file to save a copy of the HTML into |
|           | xpath | XPath expression for the elements to find |
|           | attribute | Attribute of the selected elements to save |
|           | into | YAML key to save the attributes to |
|           | data | array or nested array to iterate over |
|           | keys | keys of data array: name to use in string expansion, key to use in data array |
| details   |      | Extract array of fields from HTML |
|           | url | The URL of the page to fetch |
|           | file | The file to save a copy of the HTML into |
|           | items | Items to extract from HTML: name/identifier and XPath expression |
|           | into | YAML key to save the fields to |
|           | data | array or nested array to iterate over |
|           | keys | keys of data array: name to use in string expansion, key to use in data array |

## Naming

Web scraping is inherently fragile.  Simple changes to a web site's HTML can result in a scraper quietly failing to retrieve values.

