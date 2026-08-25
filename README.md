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
  - [Field readers](#field-readers)
    - [The field-shape gate](#the-field-shape-gate)
    - [`neo_label`](#neo_label)
    - [`neo_value`](#neo_value)
    - [`neo_raw`](#neo_raw)
    - [`neo_target_entity`](#neo_target_entity)
  - [Beyond the render array](#beyond-the-render-array)
    - [`neo_field`](#neo_field)
    - [`neo_uri`](#neo_uri)
    - [`neo_oembed`](#neo_oembed)
    - [`neo_inspect`](#neo_inspect)
- [Helper notices](#helper-notices)
- [Attribute variables](#attribute-variables)

## Introduction

Provides twig helpers for Drupal.

## Requirements

This module requires no other module — not a contributed one, and not a core one either. That is a
deliberate property rather than an accident: every service a helper needs is resolved at the moment
it is needed rather than injected, so installing this module adds nothing to a site's dependency
graph and it can go anywhere.

One helper is the exception worth naming. [`neo_oembed`](#neo_oembed) resolves core's **Media**
module's oEmbed services — the url resolver, the resource fetcher and the iframe url helper — so it
works only on a site where Media is installed. Nothing else in the module goes near them.

## Installation

Install as you would normally install a contributed Drupal module. Visit
<https://www.drupal.org/node/1897420> for further information.

## Twig helpers

The helpers are grouped by what they do. The **attribute writers** write to one element; the
**children walkers** walk a render array and hand each thing they find to a writer; the **field
readers** read a field's render array rather than writing to one; and four more need something
beyond a render array altogether.

Twelve of the fifteen are filters, and are piped. `neo_uri`, `neo_oembed` and `neo_inspect` are
Twig functions, and are called.

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
  entirely. Two things found at the resolved key are written into in place instead, which leaves
  nothing array-shaped to mirror: a `Url`, whose own options are already a set of url options,
  carries no mirror from any of the three, and an `Attribute` object carries none from `neo_class`
  or `neo_attributes`. `neo_attribute` mirrors over an `Attribute` all the same, because the name
  and value it was handed are its payload whatever it found at the key.

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

### Field readers

Four filters read a field's render array instead of writing to one: `neo_label`, `neo_value`,
`neo_raw` and `neo_target_entity`. Each begins with the same check, and each answers `NULL` when
that check fails.

#### The field-shape gate

All four ask one question before anything else: does the value carry `#theme: 'field'`? That is
what the field formatter puts there, and it is the only thing any of the four accepts. A string, an
entity, a whole `content` build, a field's `#items` unpacked by the template — none of them is a
field's render array, and each answers `NULL`.

`NULL` is also the answer three of them give when the value did pass the gate and there was simply
nothing in it. **From the return value alone, "that is not a field" and "that field is empty" are
the same answer.** They are two different mistakes with two different fixes, and the way to tell
them apart is to turn the [helper notice](#helper-notices) on: with the gate off, a template can
only see the `NULL`.

#### `neo_label`

Returns a field's label.

| Argument | Default | Meaning |
|---|---|---|
| the piped value | — | A field's render array. |

Normally the label is the build's `#title`. A site running the `field_labels` module can override
it per field, and that override wins where one is set — from a base field's `display_label`
setting, or from a configurable field's `field_labels` / `display_label` third-party setting on a
build that is not using the field's own default label.

Answers `NULL` when the value is not a field's render array, and when the field carries no label to
answer with. See [the field-shape gate](#the-field-shape-gate).

```twig
<dt>{{ content.field_tags|neo_label }}</dt>
```

#### `neo_value`

Returns a field's rendered values, without the label and wrapper the field template would add.

| Argument | Default | Meaning |
|---|---|---|
| the piped value | — | A field's render array. |

Returns the build's children, keyed by delta and in the order the build held them — each one the
render array the formatter produced for that value. Always an array: a single-value field comes
back as a one-item list, unlike `neo_raw` and `neo_target_entity` below.

Answers `NULL` when the value is not a field's render array, and when it has no children at all —
an empty field. See [the field-shape gate](#the-field-shape-gate).

```twig
<ul>
  {% for item in content.field_tags|neo_value %}
    <li>{{ item }}</li>
  {% endfor %}
</ul>
```

#### `neo_raw`

Returns a field's stored values, read off `#items` rather than out of the rendered output.

| Argument | Default | Meaning |
|---|---|---|
| the piped value | — | A field's render array. |
| `key` | `''` | The name of one property to read from each value — `value`, `uri`, `target_id`, and so on. Omit it for the whole value array of each delta. A delta that has no property of that name answers `NULL` in its slot. |

**A single value collapses.** More than one value comes back keyed by delta; exactly one comes back
as the value itself, not as a one-item list. A template that iterates the result therefore walks
the *values* of a multi-value field and the *properties* of the single value on a single-value
field. Guard on what you get, or reach for `neo_value` when the rendered items are what you want.

Answers `NULL` for three misses: the value is not a field's render array; its `#items` is absent or
is not typed data — usually an array themed as a field by something other than the field formatter;
or the items are readable and hold no values. See [the field-shape gate](#the-field-shape-gate).

```twig
{# One property of the one value a single-value link field holds. #}
<a href="{{ content.field_link|neo_raw('uri') }}">Read more</a>

{# Every property of every value. #}
{% set raw = content.field_addresses|neo_raw %}
```

#### `neo_target_entity`

Returns the entities a reference field points at — an image, a file, a taxonomy term, a referenced
node.

| Argument | Default | Meaning |
|---|---|---|
| the piped value | — | A reference field's render array. |

It reads the field's name from `#field_name`, finds the parent object under `#object` or
`#field_collection_item` — those two keys and no others — asks that object for the field, and
collects the entity behind each item.

**A single entity collapses**, exactly as `neo_raw`'s single value does: two targets come back as a
list, one target comes back as the entity itself. Iterating the result walks a one-entity field's
own properties rather than its targets.

Answers `NULL` for three misses: the value is not a field's render array; it carries no
`#field_name`; or it carries no parent object under either key it reads. There is a fourth answer
worth knowing, documented here as it stands rather than repaired: a field that is there and holds
no entity answers **`FALSE`**, not `NULL`, because the collapse is a `reset()` over an empty list.
Guard on truthiness rather than on `is null`. See [the field-shape gate](#the-field-shape-gate).

```twig
{% set media = content.field_image|neo_target_entity %}
{% if media %}
  <img src="{{ file_url(media.field_media_image.entity.uri.value) }}" alt="">
{% endif %}
```

### Beyond the render array

Four helpers need something more than the array they were handed. `neo_field` reaches into a build
for an entity to ask; `neo_uri` and `neo_oembed` reach out to Drupal's url and oEmbed machinery;
`neo_inspect` reaches for the template's own context. `neo_field` is a filter; the other three are
Twig functions, and are called rather than piped.

#### `neo_field`

Renders one named field of a content entity found inside a build, in that build's own view mode.

| Argument | Default | Meaning |
|---|---|---|
| the piped value | — | The render array an entity was rendered into. |
| `field_id` | required | The machine name of the field to render. |

It takes no entity argument. It sweeps the build's own values for the first content entity among
them and asks that one, and it reads the view mode off the build's `#view_mode` rather than being
told. What comes back is the field's render array, as the entity's own display produced it.

Answers `NULL` for four different misses, and **the return value does not say which**: the value
was never a render array; it carries no view mode (absent, or the empty string — the same miss from
two sides); it holds no content entity to ask; or the entity it found has no field by that name. A
typo in a field name is the last of those, and from inside a template it is an empty region and
nothing else. Turn the [helper notice](#helper-notices) on and the answer names the one that fired,
including the field name it looked for.

```twig
{# In a build that rendered an entity: pull one more of its fields in. #}
{{ content.field_related|neo_field('field_summary') }}
```

#### `neo_uri`

Resolves a uri to a url string. The module's most-used helper.

| Argument | Default | Meaning |
|---|---|---|
| `uri` | required | The uri to resolve: `route:…`, `internal:/…`, `entity:…`, `base:…`, an external url, a rooted path, a bare `#fragment` or `?query`. |
| `options` | `[]` | Url options — `query`, `fragment`, `absolute`, and so on. `NULL` is normalised to `[]` first, so `link.options` on a link that carries none is safe to pass. |
| `destination` | `false` | Whether to add the current page as a `destination` query parameter. |

Returns a string. Five behaviours are worth knowing before the first call:

- **An empty uri answers the current route**, so a link to nowhere in particular links to the page
  it is printed on. PHP's `empty()` decides, so `NULL` and the string `'0'` take that branch
  alongside `''`. Options are honoured there too.
- **A non-linking uri answers the empty string.** `<nolink>`, `<none>` and `<button>` — in the bare
  form a component author writes, or the `route:` form `Url::toUriString()` produces — are
  deliberately path-less, and core renders them as a `<span>` rather than a link. The empty string
  keeps an un-updated template on a dead `href` instead of navigating somewhere the author never
  named. Suppressing the anchor itself is the template's job: guard on the uri before calling.
- **Resolution runs in two stages.** `Url::fromUri()` first; if it rejects the uri — or builds a
  `Url` whose `toString()` then raises — `Url::fromUserInput()` is tried. That second pass is an
  ordinary success rather than a failure: every rooted path, bare fragment and bare query string a
  template holds arrives with no scheme and is resolved there.
- **`/` is the answer when both passes fail.** An unresolvable uri quietly becomes a link to the
  front page rather than fatalling the page it is printed on. Nothing about the rendered markup
  says so; the [helper notice](#helper-notices) is what tells you.
- **The destination parameter is written first**, before any branch above, so it joins the query
  the caller already had rather than replacing it. A non-linking uri still answers the empty
  string, carrying no destination anywhere.

```twig
<a href="{{ neo_uri(item.link.uri, item.link.options) }}">{{ item.link.title }}</a>

{# Come back to this page afterwards. #}
<a href="{{ neo_uri('internal:/user/login', {}, true) }}">Sign in</a>
```

#### `neo_oembed`

Builds a render element from an oEmbed url. Needs core's Media module — see
[Requirements](#requirements).

| Argument | Default | Meaning |
|---|---|---|
| `url` | required | The url of the resource to embed. |
| `max_width` | `0` | Maximum width to ask the provider for. `0` asks for no constraint. |
| `max_height` | `0` | Maximum height, on the same terms. |

What comes back follows the resource type the provider reports:

- a **link** resource becomes a `#type: link` element carrying the resource's title;
- a **photo** becomes a `#theme: image` element at the resource's own url, width and height,
  loading lazily;
- anything else — a video, a rich resource — becomes an `iframe` html tag pointed at core's
  `media.oembed_iframe` route, sized from the resource (falling back to the maximums given here),
  carrying core's `media/oembed.formatter` library and the resource's title as its `title`
  attribute. A site that has set `iframe_domain` in `media.settings` gets the iframe built against
  that domain.

Answers an empty array twice: for an empty url, which is answered before any service is touched,
and for a resource that could not be fetched. That second one is logged as an **error** on the
`neo_twig` channel whether or not Twig debugging is on — a remote call failing is worth saying out
loud — and is not one of the [helper notices](#helper-notices).

```twig
{{ neo_oembed(remote_video.src) }}
{{ neo_oembed(remote_video.src, 800, 450) }}
```

#### `neo_inspect`

Prints what a template can print. A debugging tool, gated on the same switch as the
[helper notices](#helper-notices), which answers "what can I put in here?" without a devel or kint
dependency.

| Argument | Default | Meaning |
|---|---|---|
| `var` | `NULL` | The value to inspect, normally a render array. Omit it — or name a variable that does not exist — to list the whole context instead. |
| `depth` | `2` | How many levels of children to walk. |

Returns an HTML table, already marked safe, so `{{ }}` prints it.

Called **with a value**, it lists that value's printable children — one row each, nested to
`depth`, giving the key you would print, what the child is (`#type` or `#theme`, or `#markup` /
`#plain_text`, or the PHP type of a non-array), and its `#title`. Properties are not children and
are left out; the `#type`, `#theme` and `#theme_wrappers` of the value itself appear in the
caption. A value with no printable children says so, which is the answer to "why does `{{ thing.x }}`
print nothing" — it renders as a whole. A non-array value comes back as a one-line box naming its
class or type.

Called **with no argument**, it lists every variable in scope with a one-phrase description of each,
and names one you could pass in. Twig's own internals and anything else keyed with a leading
underscore are left out.

Returns the empty string when Twig debugging is off, so a `neo_inspect()` committed by accident
prints nothing on a deployed site.

```twig
{# What is in scope in this template? #}
{{ neo_inspect() }}

{# What can I print out of this one? #}
{{ neo_inspect(content) }}
{{ neo_inspect(content, 3) }}
```

## Helper notices

Every helper here has at least one way of doing nothing: a value that arrived empty, a key that
reached no element, a build that was never a field's. Each of those gives up quietly — the value
comes back exactly as it went in, or `NULL` — which is the right answer on a live site and an
unhelpful one while a template is being written, because nothing distinguishes "the helper did its
job and there was nothing to do" from "the helper never ran".

**With Twig debugging on, a helper that returns early says so.** The notice names the helper by the
name a template types, what it expected, and what actually arrived:

```
neo_class: expected a render array or a Link, received NULL
neo_property_class: expected an array under #items, received NULL
neo_field: expected a field named "field_subtitle" on the entity it found, received Node
neo_uri: expected a uri Drupal can resolve to a url, received string: not a uri
```

It reaches two surfaces, and the shape of the answer picks which.

- **The log** takes every one of them, on the `neo_twig` channel at debug level. It is the only
  surface a helper answering `NULL`, a string or a `Link` can reach. The same message is written
  **once per request** however many calls produced it — fifty identical lines from one field
  template would make the log useless — while a different helper, a different expectation or a
  different value arriving is a different message and gets its own line.
- **Beside the element**, when the helper had a non-empty render array to hand back, the same line
  is attached to it and renders where that element renders — inside core's own Twig-debug comments,
  which name the template that made the call. That is the mysterious case: a real value went in, a
  real value came out, and nothing about it changed. This surface is never deduplicated, because
  the notice belongs to that element rather than to the request. An empty value never carries one:
  attaching to it would make it truthy, and a `{% if thing %}` guard would render a branch
  production does not.

**What turns it on** is `twig.config.debug` — the same switch that turns on core's Twig debugging
and its `<!-- FILE NAME SUGGESTIONS -->` comments — set in `sites/default/services.yml`, or in
whichever development services file the site includes, and picked up on the next container rebuild.

**What it costs.** With the gate on, a description of the offending value is built and a log line is
written, and any render array carrying a notice renders extra markup — so this belongs on a
development environment and nowhere else. With the gate off, which is every deployed environment,
**nothing happens at all**: a helper that gives up reads one boolean and returns. No message is
built, no logger is resolved, no markup changes, nothing is logged, and no page renders differently
than it did before.

## Attribute variables

The other half of what this module provides. Three of core's formatter templates have nowhere for a
theme to put attributes on the markup they build, so the module widens them:
`hook_theme_registry_alter()` adds the variables, and a preprocess hook per template consumes
whatever they are handed. Every one of them defaults to `NULL`, so a template that never sets one
renders exactly what core's rendered before.

| Template | Variable | What the module's preprocess does with it |
|---|---|---|
| `image_formatter` | `image_attributes` | Deep-merged into the image element's `#attributes`. |
| `image_formatter` | `link_attributes` | Merged into the url's own options, under `attributes`, so they land on the anchor the formatter builds around the image. |
| `responsive_image_formatter` | `image_attributes` | Deep-merged into the responsive image element's `#attributes`. |
| `responsive_image_formatter` | `link_attributes` | Wrapped in an `Attribute` object and handed back as the variable. **The template has to print it** — nothing else does. |
| `file_link` | `link_attributes` | Merged into the link's options, after which the whole link is rebuilt from them. Rebuilding is the only way attributes reach a link core has already built. |

Set them the way any theme variable is set — as a `#`-prefixed key on the element, or from a
preprocess hook:

```php
$build = [
  '#theme' => 'image_formatter',
  '#item' => $item,
  '#url' => $url,
  // Both added by this module.
  '#image_attributes' => ['class' => ['rounded-lg', 'w-full']],
  '#link_attributes' => ['class' => ['block'], 'data-lightbox' => 'gallery'],
];
```

The responsive image formatter is the one that needs a template to finish the job, because its
`link_attributes` arrive as an `Attribute` object rather than being merged anywhere:

```twig
{# responsive-image-formatter.html.twig #}
<a{{ link_attributes|without('href') }} href="{{ url }}">
  {{ responsive_image }}
</a>
```
