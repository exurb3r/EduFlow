---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## LengthAwarePaginator passed to Inertia serialises flat (no meta key)
Passing a raw `->paginate()` result to `Inertia::render()` serialises FLAT: `{data, current_page, from, to, total, last_page, links, ...}`. There is NO nested `meta` object — that wrapper only appears via API Resources or `Inertia::scroll()`. Type it as a flat `Paginated<T>` and assert `requests.total`, never `requests.meta.total`.
