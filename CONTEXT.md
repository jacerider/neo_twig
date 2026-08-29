# CONTEXT — neo_twig

Terms specific to Neo's Twig helpers: the filters and functions templates call, and the rules
they share. One entry per term: what it IS, then the names not to use for it.

## Twig helpers (`neo_twig`)

**Twig helper** — one of the fifteen filters and functions `neo_twig` registers for templates:
`{{ build|neo_class('x') }}`, `{{ neo_uri(uri) }}`. The **registered name** is the contract, not
the PHP method behind it — a template author writes `neo_child_class`, never `addChildClass` —
and the README documents all fifteen under that name, with the arguments the method really takes.
_Avoid:_ "twig function" for the filters, "tool", and the method name where the registered name is
meant.

**Attribute writer** — the three helpers that mutate one element's attributes: `neo_class`,
`neo_attributes` and `neo_attribute`. Each accepts a render array, a `Link` or a `Url`, resolves
the key it writes to (optionally through a parents path) and writes into it. They share a
vocabulary and, through the **target resolver**, one answer to where the write lands; each still
owns its own write. _Avoid:_ "attribute filter", "class filter" for `neo_class` alone.

**Link branch** — the early-return path an **attribute writer** takes when the value handed to it
is a `\Drupal\Core\Link` object rather than a render array: it reads the link's `Url` options,
writes into the `attributes` key there, calls `setOptions()` and returns the same `Link`. It is a
different path from the **link-element mirror**, which is what a render array carrying
`#type: link` gets. _Avoid:_ "link handling", and using it for a `#type: link` element.

**Link-element mirror** — the second write `neo_class` and `neo_attribute` make when the render
array they are writing into carries `#type: link`: the same value is copied into
`#options.attributes`, because a link element renders from `#options` and not from the element's
own attributes property. All three make it, each mirroring the payload it just wrote. _Avoid:_
"the options copy", and confusing it with the **Link branch**.

**Children walker** — a helper that iterates a value's children or items and delegates the actual
write to an **attribute writer**: `neo_child_class`, `neo_child_attribute` and `neo_property_class`
(which walks the items under a hash-prefixed property rather than the element's children).
`neo_children` is the read-only member of the family — it returns the children instead of writing
to them. A difference between two of these is a difference in what they walk, never in what they
write. _Avoid:_ "child filter", "fan-out helper".

**Write target** — where an **attribute writer**'s write actually lands: the parents path, the key
after the **hash-prefix rule** has been applied to it, and the element found at that path. A writer
computes one before it writes anything and commits it afterwards. _Avoid:_ "the key", "the
destination array".

**Target resolver** — the single seam that computes a **write target** for all three **attribute
writers**, commits the mutated element back through the parents path, and applies the
**link-element mirror** to the payload the writer hands it. It owns key resolution; each writer
still owns its own write. _Avoid:_ "the preamble", "resolveKey".

**Hash-prefix rule** — how the **target resolver** turns the key an **attribute writer** was handed
into the key it writes to: `attributes` becomes `#attributes` **only when the value carries no bare
key of that name**, so a bare key that something already reads is written where it is read. One
rule, for all three writers. _Avoid:_ "the property rule", and speaking of it in the plural as
though each writer had its own.

**Field-shape gate** — the `#theme: field` check that `neo_label`, `neo_value`, `neo_raw` and
`neo_target_entity` each run before doing anything, and whose failure answer is `NULL`. Anything
that is not a field's render array — a whole entity, a bare list, a string — gets `NULL` rather
than an error, so the *return value* cannot tell "not a field" from "a field with nothing in it";
the **helper notice** each of them makes under the **debug gate** is what distinguishes the two.
_Avoid:_ "field check", "field guard".

**Non-linking uri** — `<nolink>`, `<none>` or `<button>`, in the bare form a component author
writes or the `route:` form `Url::toUriString()` produces. `neo_uri` answers the empty string for
one, because core renders these as a span and neither of its resolution paths can say so.
Suppressing the anchor itself is the template's job. _Avoid:_ "empty link", "null route".

**Debug gate** — `twig.config.debug`, read once into the Twig extension at construction and the
only switch any helper consults. `neo_inspect` returns an empty string when it is off, so a call
committed by accident is inert on every deployed environment, and every **helper notice** is
checked against it before a message is built — which is why a deployed site pays one boolean per
guard and renders exactly what it rendered before. _Avoid:_ "verbose mode", "dev mode" (that is Neo
DEV mode and `_neo.lock`, an unrelated thing).

**Helper notice** — the one line a **Twig helper** says, under the **debug gate**, when it returns
early without doing its job: which helper, what it expected, and what it received. It never changes
what the helper returns, never raises and never throws — a template that worked keeps working, and
one that quietly did nothing now says so. _Avoid:_ "error", "warning" (nothing is raised and nothing
is thrown), "debug message".

**Notice log** — the surface every **helper notice** reaches: the module's own logger channel, at
debug level, resolved lazily the way `neo_oembed` already resolves its services. The same message is
logged once per request however many call sites produce it, so a page that pipes fifty empty values
through a helper leaves one line rather than fifty. _Avoid:_ "watchdog" for the deduplication rule,
and treating it as a production channel — nothing reaches it with the **debug gate** off.

**Inline notice** — the second surface a **helper notice** takes when the helper has a non-empty
render array to hand back: the same line, in `neo_inspect`'s box, attached to that element so it
renders where the element renders — inside core's own Twig-debug output markers, which is what says
which template made the call. It is never attached to an empty value, because that would make an
empty value truthy and change what a dev template does. _Avoid:_ "inline error", and expecting one
where the helper answers `NULL`, a string or a `Link`.

**Attribute variable** — one of the template variables `neo_twig`'s hooks add to three core
templates: `image_attributes` and `link_attributes` on `image_formatter` and
`responsive_image_formatter`, and `link_attributes` on `file_link`. Something sets one, and the
module's own preprocess merges it into the image element, into the url's options, or into a fresh
`Attribute` object for the template to print — which is how an **attribute writer**'s write reaches
markup core has already built. `neo_image`'s shipped template is built on them. _Avoid:_ "the image
attributes hook", and confusing them with an element's own `#attributes`.

