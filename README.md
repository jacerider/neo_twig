# Neo | Twig

## Contents

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installation](#installation)
- [Twig helpers](#twig-helpers)
  - [Attribute writers](#attribute-writers)
    - [Where a write lands](#where-a-write-lands)
    - [`neo_class`](#neo_class)
    - [`neo_attributes`](#neo_attributes)
    - [`neo_attribute`](#neo_attribute)
  - [Children walkers](#children-walkers)
    - [`neo_child_class`](#neo_child_class)
    - [`neo_child_attribute`](#neo_child_attribute)
    - [`neo_property_class`](#neo_property_class)
    - [`neo_children`](#neo_children)

## Introduction

Provides twig helpers for Drupal.

## Requirements

This module requires no modules outside of Drupal core.

## Installation

Install as you would normally install a contributed Drupal module. Visit
<https://www.drupal.org/node/1897420> for further information.

## Twig helpers

The helpers are grouped by what they do. The **attribute writers** write to one element; the
**children walkers** walk a render array and hand each thing they find to a writer.

### Attribute writers

Three filters write attributes onto a renderable: `neo_class`, `neo_attributes` and
`neo_attribute`. They differ only in what they write. Where that write lands is the same question
for all three, and it is answered once below.

#### Where a write lands

Every one of the three takes the same final `key` argument and means the same thing by it.

- **The key defaults to `attributes`.** It names the property the write goes into.
- **An array key is a parents path.** The last entry is the key; everything before it is a path to
  the element that holds it, so a write can reach a nested element without the template unpacking
  it first. A path that resolves to nothing writes nothing and creates nothing — the value comes
  back exactly as it went in.
- **The hash-prefix rule.** The resolved key is prefixed with `#` — `attributes` becoming
  `#attributes` — **only when the element carries no bare key of that name**. A bare key that is
  already there is a key something already reads, so the write goes where it is read, and no
  second, hash-prefixed key is invented beside it.
- **The Link branch.** A `Link` has no render array to write to, so a `Link` handed to any of the
  three is reached through: the write is merged into the options of the `Url` it wraps, under
  `attributes`. The same `Link` comes back, mutated in place, so a template can keep piping it.
- **The link-element mirror.** On a render array carrying `#type: link` the write is mirrored into
  `#options['attributes']` as well, because a link element renders out of its options rather than
  out of the property that was just written. Whatever the options already held survives: a
  mirrored class list is appended to it, and an attribute the write does not mention is left
  alone. No other element is mirrored into — an `#options` key anywhere else means something else
  entirely. A `Url` found at the resolved key is the one write that carries no mirror; it is
  written into in place, and its own options are already a set of url options.

An empty value, or a value that is neither a render array nor a `Link`, comes back unchanged.

#### `neo_class`

Merges one or more classes into a renderable's attributes.

| Argument | Default | Meaning |
|---|---|---|
| the piped value | — | A render array, or a `Link`. |
| `classes` | required | A single class, a list of classes, or a list containing a list. A member that is itself a list is imploded on a space, so a nested list still arrives as a flat class list. |
| `key` | `'attributes'` | Where the write lands — see [Where a write lands](#where-a-write-lands). |

Returns the value it was handed, with the classes merged in: a render array comes back a render
array, a `Link` comes back the same `Link`. It is a merge, not an assignment — whatever the
element arrived with survives and the new classes are appended after it, in order.

An `Attribute` object at the resolved key is handed the classes through its own `addClass()` and
stays an `Attribute`. A `Url` there has them merged into its `attributes` option and stays a `Url`.

```twig
{{ content.field_image|neo_class('rounded-lg') }}
{{ content.field_image|neo_class(['rounded-lg', 'shadow']) }}

{# The last entry is the key; the rest is a path to the element holding it. #}
{{ build|neo_class('is-active', ['wrapper', 'inner', 'attributes']) }}
```

#### `neo_attributes`

Merges a whole set of attributes into a renderable's attributes.

| Argument | Default | Meaning |
|---|---|---|
| the piped value | — | A render array, or a `Link`. |
| `attributes` | required | An `Attribute` object, or an array of attributes keyed by name. An array is wrapped in an `Attribute` before anything else happens, so both shapes behave identically. |
| `key` | `'attributes'` | Where the write lands — see [Where a write lands](#where-a-write-lands). |

Returns the value it was handed. On a render array the resolved property comes back as an
`Attribute` object, rather than as the array it may have been. A class list is appended to rather
than replaced, and an attribute the incoming set does not mention is left alone.

An `Attribute` object already at the resolved key is merged into in place, so anything still
holding a handle to it sees the merge. A `Url` there has the set merged into its `attributes`
option and stays a `Url`.

```twig
{{ content.field_link|neo_attributes({ 'data-role': 'cta', 'class': ['btn', 'btn-primary'] }) }}
```

#### `neo_attribute`

Sets one named attribute on a renderable.

| Argument | Default | Meaning |
|---|---|---|
| the piped value | — | A render array, or a `Link`. |
| `attribute` | required | The attribute name, as a string. |
| `value` | required | The attribute value, as a string. |
| `key` | `'attributes'` | Where the write lands — see [Where a write lands](#where-a-write-lands). |

Returns the value it was handed. This one sets rather than merges: an attribute of that name
already there is replaced.

```twig
{{ content.field_link|neo_attribute('target', '_blank') }}
```

### Children walkers

Four filters walk a render array rather than writing to one element. Three of them delegate to an
attribute writer above — once per thing they walk, and taking the same `key` argument, so
everything under [Where a write lands](#where-a-write-lands) applies to each delegated write. The
fourth reads and writes nothing.

An empty value comes back unchanged, and so does a render array with no children.

#### `neo_child_class`

Walks a render array's children and adds classes to each one, through `neo_class`.

| Argument | Default | Meaning |
|---|---|---|
| the piped value | — | The render array whose children are walked. |
| `classes` | required | As `neo_class` takes them — a class, a list, or a list containing a list. |
| `key` | `'attributes'` | Where each child's write lands. |
| `prop` | `NULL` | A property name to filter the children on. It is hash-prefixed for you, so `field_name` matches the `#field_name` property; a name that already starts with `#` is not prefixed twice. |
| `propValue` | `NULL` | The value that property must hold. Compared with `===`. |

Returns the render array, with the matching children written to. Both `prop` and `propValue` are
needed to narrow the walk: a property name given with no value disables the filter rather than
narrowing it, and every child is written to.

```twig
{{ content.field_images|neo_child_class('mb-4') }}

{# Only the children whose #field_name property is 'title'. #}
{{ content|neo_child_class('sr-only', 'attributes', 'field_name', 'title') }}
```

#### `neo_child_attribute`

Walks a render array's children and sets one named attribute on each, through `neo_attribute`.

| Argument | Default | Meaning |
|---|---|---|
| the piped value | — | The render array whose children are walked. |
| `attribute` | required | The attribute name, as a string. |
| `value` | required | The attribute value, as a string. |
| `key` | `'attributes'` | Where each child's write lands. |

Returns the render array, with every child written to. There is no property filter here — this
one walks all of them.

```twig
{{ content.field_images|neo_child_attribute('loading', 'lazy') }}
```

#### `neo_property_class`

Walks the items under a named property — not the array's children — and adds classes to each,
through `neo_class`.

| Argument | Default | Meaning |
|---|---|---|
| the piped value | — | The render array holding the property. |
| `classes` | required | As `neo_class` takes them. |
| `property` | `'items'` | The property to walk. |
| `key` | `'attributes'` | Where each item's write lands. |

Returns the render array, with every item under the property written to. Unlike the key the
writers resolve, the property name is **always** hash-prefixed: `items` resolves `#items`, and a
name that already starts with `#` is not prefixed twice. There is no bare-key fallback — a value
carrying a bare `items` key comes back exactly as it arrived. The array's own children are not
what this one walks.

```twig
{# The field's #items, one class on each item. #}
{{ content.field_tags|neo_property_class('badge') }}

{{ build|neo_property_class('px-4', 'rows') }}
```

#### `neo_children`

Returns a render array's children, optionally sorted by weight. The read-only member of the
family: it writes nothing.

| Argument | Default | Meaning |
|---|---|---|
| the piped value | — | The render array whose children are returned. |
| `sort` | `false` | Whether to sort the children by weight. |

Returns the children themselves — whole, and keyed as they were. Every property is dropped,
including the `#sorted` marker a sort leaves behind. Unsorted, they come back in declaration
order; sorted, in weight order, with a child carrying no `#weight` counting as zero. The sort
happens on a copy, so the array the template is still holding keeps its own order.

```twig
<ul>
  {% for child in content.field_tags|neo_children(true) %}
    <li>{{ child }}</li>
  {% endfor %}
</ul>
```
