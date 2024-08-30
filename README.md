CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Requirements
 * Installation
 * Tools


INTRODUCTION
------------

Provides twig helpers for Drupal.


REQUIREMENTS
------------

This module requires no modules outside of Drupal core.


INSTALLATION
------------

Install as you would normally install a contributed Drupal module. Visit
https://www.drupal.org/node/1897420 for further information.

TOOLS
-----

## Children

Get children of a content field.

```twig
<ul>
  {% for tag in content.field_tags|children %}
    <li>{{ tag }}</li>
  {% endfor %}
</ul>
```

## Children

Render a field from a nested entity reference field.

```twig
{% for key, item in content.field_reference|neo_children %}
  {{ item|neo_field('field_image') }}
{% endfor %}
```
