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

## Class

Add a class to an element.

```twig
{{ field_image|neo_class('bg-base-500') }}
```

## Class to Children

Add a class to an children of a renderable.

```twig
{{ field_images|neo_child_class('bg-base-500') }}
```

You can also target nested elements within a child by defining the path to the
nested element.

```twig
{{ field_images|neo_child_class('bg-base-500', ['images', 'image', '#attributes']) }}
```

## Children

Get children of a content field.

```twig
<ul>
  {% for tag in content.field_tags|neo_children %}
    <li>{{ tag }}</li>
  {% endfor %}
</ul>
```

## Field

Render a field from a nested entity reference field.

```twig
{% for key, item in content.field_reference|neo_children %}
  {{ item|neo_field('field_image') }}
{% endfor %}
```
