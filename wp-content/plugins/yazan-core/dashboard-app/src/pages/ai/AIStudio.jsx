import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { aiApi, mediaApi, productsApi } from '../../api/endpoints.js'
import { useToast } from '../../context/ToastContext.jsx'
import { PageHeader } from '../../components/Layout.jsx'
import {
  Alert,
  Badge,
  Button,
  Card,
  Checkbox,
  Field,
  Input,
  Select,
  Spinner,
  Textarea,
} from '../../components/ui/index.js'

/* ------------------------------------------------------------------ */
/* Marketing channels + the one-click pipeline preference              */
/* ------------------------------------------------------------------ */

// Mirrors Yazan_AI_Marketing::CHANNELS — a key the PHP doesn't know is silently intersected away.
const MKT_CHANNELS = [
  { key: 'instagram', label: 'Instagram' },
  { key: 'story', label: 'Story' },
  { key: 'facebook_ad', label: 'Facebook ad' },
  { key: 'google_ads', label: 'Google Ads' },
  { key: 'email', label: 'Email' },
  { key: 'promo', label: 'Promo' },
  { key: 'launch', label: 'Launch' },
  { key: 'retargeting', label: 'Retargeting' },
]

/** The auto-chain starts narrower than the manual card's four — two channels cover most posts. */
const AUTO_DEFAULT_CHANNELS = ['instagram', 'facebook_ad']
const AUTOPILOT_KEY = 'yazan-dash-ai-autopilot'
const STEP_LABEL = { seo: 'SEO', marketing: 'marketing' } // mid-sentence: "Generating marketing… 2/2"
const STEP_TITLE = { seo: 'SEO', marketing: 'Marketing' } // sentence-start: "Marketing generation failed"

/** One key, one blob: the three values are always read and written together. */
function loadAutopilot() {
  try {
    const raw = JSON.parse(localStorage.getItem(AUTOPILOT_KEY) || '{}')
    return {
      seo: Boolean(raw.seo),
      marketing: Boolean(raw.marketing),
      // Filtered through MKT_CHANNELS so a stale or renamed key can't survive into a request.
      channels:
        Array.isArray(raw.channels) && raw.channels.length > 0
          ? MKT_CHANNELS.filter((c) => raw.channels.includes(c.key)).map((c) => c.key)
          : AUTO_DEFAULT_CHANNELS,
    }
  } catch {
    // Private mode / storage disabled — the preference simply doesn't stick.
    return { seo: false, marketing: false, channels: AUTO_DEFAULT_CHANNELS }
  }
}

function saveAutopilot(next) {
  try {
    localStorage.setItem(AUTOPILOT_KEY, JSON.stringify(next))
  } catch {
    // Private mode / storage disabled — the choice still applies for this session.
  }
}

/** The 8 channels as toggle chips — shared by the auto-chain selector and the manual card. */
function ChannelChips({ value, onToggle, disabled }) {
  return (
    <div className="flex flex-wrap gap-2 mt-1">
      {MKT_CHANNELS.map((c) => {
        const on = value.includes(c.key)
        return (
          <button
            key={c.key}
            type="button"
            disabled={disabled}
            aria-pressed={on}
            onClick={() => onToggle(c.key)}
            className={`yz-badge ${on ? 'border-gold text-gold' : 'border-edge text-muted'} disabled:opacity-50`}
          >
            {c.label}
          </button>
        )
      })}
    </div>
  )
}

/**
 * AI Studio — the flagship admin flow: a product photo becomes a complete, on-brand, bilingual draft
 * listing (title, descriptions, story, SEO, tags, and jewelry attributes chosen from the store's real
 * terms). The owner reviews, then creates a draft product — written through the normal products
 * controller so it lands in WooCommerce exactly like a hand-made product. A second card runs the SEO
 * and marketing pipelines against an existing product.
 */
export default function AIStudio() {
  // The AI Studio works on ONE product end-to-end. It becomes "the product" either by creating a draft
  // from a photo below, or by opening the studio for an existing product (?product=<id>). SEO,
  // marketing, and gallery all apply to that same product — no separate search or ID entry.
  const [product, setProduct] = useState(null) // { id, name } | null
  // A one-click pipeline request handed over by the photo card after it creates the draft:
  // { token, productId, seo, marketing, channels } | null. ContentTools runs it.
  const [chain, setChain] = useState(null)
  const productId = product ? String(product.id) : ''

  // Deep-link support: /dashboard/ai?product=123 opens the studio already linked to that product.
  useEffect(() => {
    const pid = Number(new URLSearchParams(window.location.search).get('product'))
    if (!pid) return
    productsApi
      .get(pid)
      .then((p) => setProduct({ id: p.id, name: p.name }))
      .catch(() => {})
  }, [])

  return (
    <>
      <PageHeader title="AI Studio" subtitle="Turn a product photo into a complete YAZAN listing" />
      <PhotoToListing
        productId={productId}
        onDraftCreated={(p, request) => {
          setProduct(p)
          setChain(request)
        }}
      />
      <ExistingProduct product={product} productId={productId} chain={chain} />
    </>
  )
}

/* ------------------------------------------------------------------ */
/* The product in focus — SEO & marketing target it (gallery lives in the photo card) */
/* ------------------------------------------------------------------ */

function ExistingProduct({ product, productId, chain }) {
  return (
    <div className="mt-6">
      <Card title="Product in focus">
        {product ? (
          <p className="text-sm text-ok">
            Working on “{product.name}” · #{product.id} — SEO &amp; marketing below apply to it.{' '}
            {/* The one-click pipeline suppresses the auto-redirect, so offer the editor here. */}
            <Link to={`/products/${product.id}`} className="text-gold hover:underline">
              Open in Products →
            </Link>
          </p>
        ) : (
          <p className="text-sm text-muted">
            Generate a listing from a photo above and click <b>Create draft product</b> — SEO and marketing will then
            apply to that product. (Opening AI Studio from a product’s page links it here too.)
          </p>
        )}
      </Card>

      <div className="mt-5">
        <ContentTools productId={productId} chain={chain} />
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Gallery panel — sits to the RIGHT of the main photo.                */
/* Gated on the main photo; 3 modes: None (default) / AI / Local.      */
/* ------------------------------------------------------------------ */

function GalleryPanel({ media, productId, images, onChange }) {
  const toast = useToast()
  const fileRef = useRef(null)
  const [mode, setMode] = useState('off') // 'off' (None) · 'ai' · 'manual' (Local)
  const [count, setCount] = useState(3)
  const [prompt, setPrompt] = useState('')
  const [busy, setBusy] = useState('')

  // Persist a gallery change: locally always; also to the product when one is in focus (live edit).
  const commit = async (next) => {
    onChange(next)
    if (productId) {
      try {
        await productsApi.update(Number(productId), { images: { gallery: next.map((g) => g.id) } })
      } catch (err) {
        toast.error(err.message)
      }
    }
  }

  const removeImage = (imgId) => commit(images.filter((g) => g.id !== imgId))

  const addLocalFiles = async (event) => {
    const files = Array.from(event.target.files || [])
    event.target.value = '' // allow re-selecting the same files later
    if (!files.length) return
    setBusy('local')
    try {
      const uploaded = []
      for (const file of files) {
        const res = await mediaApi.upload(file)
        uploaded.push({ id: res.id, url: res.url || res.source_url })
      }
      await commit([...images, ...uploaded])
      toast.success(`Added ${uploaded.length} image(s) to the gallery.`)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy('')
    }
  }

  const generateAI = async () => {
    if (!media) return
    setBusy('ai')
    try {
      const res = await aiApi.galleryGenerate({
        media_id: media.id,
        product_id: productId ? Number(productId) : undefined,
        count,
        prompt,
      })
      if (!res.ok) {
        toast.error(res.message || res.validation_errors?.[0] || 'Generation failed.')
        return
      }
      const added = (res.images || []).map((im) => ({ id: im.id, url: im.url }))
      await commit([...images, ...added])
      toast.success(`Generated ${added.length} image(s) · ${res.provider}. Remove any you don't want.`)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy('')
    }
  }

  // The single process button, acting per mode.
  const process = () => {
    if (mode === 'manual') fileRef.current?.click()
    else if (mode === 'ai') generateAI()
  }

  const working = busy === 'local' || busy === 'ai'

  // Gate: nothing in the gallery works until the main product photo is selected.
  if (!media) {
    return (
      <div className="border border-dashed border-edge h-52 grid place-items-center text-center px-4">
        <span className="text-sm text-muted">Select the main product photo first — the gallery is built here.</span>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-3">
      <div>
        <span className="yz-label">Gallery</span>
        <p className="text-xs text-faint mt-1">
          None (default) · Local = upload your own · AI = generate from the main photo. Curate by removing any you
          don't want. AI needs an image-capable provider (OpenAI or Gemini) in AI Settings.
        </p>
      </div>

      {images.length > 0 && (
        <div className="grid grid-cols-3 sm:grid-cols-4 gap-2">
          {images.map((g) => (
            <div key={g.id} className="relative border border-edge overflow-hidden">
              <img src={g.url} alt="" className="w-full aspect-square object-cover" />
              <button
                type="button"
                onClick={() => removeImage(g.id)}
                title="Remove"
                className="absolute top-1 right-1 size-5 grid place-items-center bg-black/60 text-white text-xs leading-none"
              >
                ×
              </button>
            </div>
          ))}
        </div>
      )}

      <div className="grid grid-cols-2 gap-3 items-end">
        <Field label="Gallery mode">
          <Select value={mode} onChange={(e) => setMode(e.target.value)}>
            <option value="off">None</option>
            <option value="ai">AI generated</option>
            <option value="manual">Local files</option>
          </Select>
        </Field>
        {mode === 'ai' && (
          <Field label="Images">
            <Input type="number" min="1" max="8" value={count} onChange={(e) => setCount(e.target.value)} />
          </Field>
        )}
      </div>

      {mode === 'ai' && (
        <Field label="Gallery prompt (optional)">
          <Textarea
            rows={2}
            value={prompt}
            onChange={(e) => setPrompt(e.target.value)}
            placeholder="e.g. warm marble surface, soft directional light, minimal props"
          />
        </Field>
      )}

      <input ref={fileRef} type="file" accept="image/*" multiple className="hidden" onChange={addLocalFiles} />

      <div className="flex items-center gap-3">
        {mode === 'off' ? (
          <p className="text-sm text-muted">No gallery will be added.</p>
        ) : (
          <Button variant="primary" onClick={process} disabled={working}>
            {busy === 'local'
              ? 'Uploading…'
              : busy === 'ai'
                ? 'Generating…'
                : mode === 'manual'
                  ? 'Add images'
                  : 'Generate gallery'}
          </Button>
        )}
        {busy === 'ai' && <Spinner label="Rendering…" />}
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Photo → listing                                                     */
/* ------------------------------------------------------------------ */

function PhotoToListing({ productId, onDraftCreated }) {
  const toast = useToast()
  const navigate = useNavigate()
  const fileRef = useRef(null)

  const [media, setMedia] = useState(null) // { id, url } — the main photo (gate + AI gallery source)
  const [galleryImages, setGalleryImages] = useState([]) // [{id,url}] — attached on create, or live-edited
  const [hints, setHints] = useState({ name: '', price: '', material: '', stone_type: '', notes: '' })
  const [instructions, setInstructions] = useState('')
  const [language, setLanguage] = useState('')
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState(null)
  const [edited, setEdited] = useState(null) // editable copy of result.suggestion — what actually gets saved
  const [chosenName, setChosenName] = useState('')
  const [creating, setCreating] = useState(false)
  const [applyLang, setApplyLang] = useState('en')

  // One-click full pipeline — opt-in, off by default, remembered across sessions. These change
  // nothing about generation; they only decide what runs AFTER the draft product is created.
  const [autopilot, setAutopilot] = useState(loadAutopilot)
  const chainToken = useRef(0) // monotonic, so a repeated request is ignored downstream
  // On the chain path we stay on the page, so the redirect no longer guards against a second
  // click making a second draft. Latch the button on the id we created instead.
  const [createdId, setCreatedId] = useState(0)

  const patchAutopilot = (patch) =>
    setAutopilot((a) => {
      const next = { ...a, ...patch }
      saveAutopilot(next)
      return next
    })

  const toggleAutoChannel = (key) =>
    patchAutopilot({
      channels: autopilot.channels.includes(key)
        ? autopilot.channels.filter((k) => k !== key)
        : [...autopilot.channels, key],
    })

  // Edit any generated field in place before creating the draft.
  const setField = (key, val) => setEdited((e) => ({ ...e, [key]: val }))
  const setSeoField = (key, val) => setEdited((e) => ({ ...e, seo: { ...(e?.seo || {}), [key]: val } }))
  const setMktField = (key, val) => setEdited((e) => ({ ...e, marketing: { ...(e?.marketing || {}), [key]: val } }))

  // Existing product opened via ?product=<id>: seed the main photo + current gallery so the panel is
  // usable (the gate passes) and edits apply live. Only seeds when nothing has been uploaded here yet.
  useEffect(() => {
    if (!productId || media) return
    let alive = true
    productsApi
      .get(Number(productId))
      .then((p) => {
        if (!alive) return
        if (p.images?.featured) setMedia({ id: p.images.featured, url: p.images.featured_url })
        setGalleryImages(p.images?.gallery || [])
      })
      .catch(() => {})
    return () => {
      alive = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [productId])

  const pickFile = async (event) => {
    const file = event.target.files?.[0]
    if (!file) return
    setBusy(true)
    try {
      const res = await mediaApi.upload(file)
      setMedia({ id: res.id, url: res.url || res.source_url })
      setResult(null)
      setEdited(null)
      setCreatedId(0) // a new photo starts a new listing — the create button unlatches
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  const generate = async () => {
    if (!media) return
    setBusy(true)
    try {
      const res = await aiApi.product({
        media_id: media.id,
        hints,
        instructions: instructions || undefined,
        language: language || undefined,
      })
      if (!res.ok) {
        toast.error(res.error?.message || 'Generation failed.')
      } else {
        setResult(res)
        setEdited(res.suggestion || {}) // editable working copy
        setChosenName(res.suggestion?.name_suggestions?.[0] || '')
        setCreatedId(0)
        toast.success(`Draft generated · ${res.meta.provider} · ${res.meta.model}`)
      }
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  const createDraft = async () => {
    if (!result) return
    const s = edited || result.suggestion // use the owner's in-place edits
    setCreating(true)
    try {
      const payload = {
        type: 'simple',
        status: 'draft',
        name: chosenName || s.name_suggestions?.[0] || 'Untitled ring',
        short_description: pick(s.short_description, applyLang),
        description: pick(s.description_html, applyLang),
        images: { featured: media.id, gallery: galleryImages.map((g) => g.id) },
        jewelry: { attributes: result.resolved?.ids || {} },
      }
      // Apply the AI's category suggestion when it matched a real store category.
      if (result.resolved?.category) {
        payload.categories = [result.resolved.category]
      }
      const created = await productsApi.create(payload)
      const chainOn = autopilot.seo || autopilot.marketing
      onDraftCreated?.(
        { id: created.id, name: created.name || (chosenName || 'Untitled ring') },
        chainOn
          ? {
              token: ++chainToken.current,
              productId: created.id,
              seo: autopilot.seo,
              marketing: autopilot.marketing,
              channels: autopilot.channels,
            }
          : null,
      )
      if (chainOn) {
        // Stay here — the chained SEO/marketing results render in the card below.
        setCreatedId(created.id)
        toast.success(`Draft #${created.id} created — generating below.`)
      } else {
        toast.success(`Draft #${created.id} created — opening it in Products.`)
        navigate(`/products/${created.id}`) // go to the new product's editor after publishing
      }
    } catch (err) {
      toast.error(err.message)
    } finally {
      setCreating(false)
    }
  }

  return (
    <Card title="Photo → listing">
      <div className="flex flex-col gap-4">
        {/* Main photo (left) + gallery (right) */}
        <div className="grid lg:grid-cols-2 gap-4 items-start">
          <button
            type="button"
            onClick={() => fileRef.current?.click()}
            className="relative border border-dashed border-edge hover:border-faint transition-colors h-64 w-full overflow-hidden"
          >
            {media ? (
              // Absolute inset gives the img a definite box (h-64); object-cover fills it edge-to-edge.
              <img src={media.url} alt="" className="absolute inset-0 h-full w-full object-cover" />
            ) : (
              <span className="absolute inset-0 grid place-items-center text-sm text-muted">
                Click to upload a product photo
              </span>
            )}
          </button>
          <GalleryPanel media={media} productId={productId} images={galleryImages} onChange={setGalleryImages} />
        </div>
        <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={pickFile} />

        <div className="grid sm:grid-cols-2 gap-3">
          <Field label="Name hint (optional)">
            <Input value={hints.name} onChange={(e) => setHints({ ...hints, name: e.target.value })} placeholder="e.g. Sana'a carnelian signet" />
          </Field>
          <Field label="Price hint (optional)">
            <Input value={hints.price} onChange={(e) => setHints({ ...hints, price: e.target.value })} placeholder="e.g. 380" />
          </Field>
          <Field label="Material (optional)">
            <Input value={hints.material} onChange={(e) => setHints({ ...hints, material: e.target.value })} placeholder="e.g. Sterling silver 925 + gold" />
          </Field>
          <Field label="Stone type (optional)">
            <Input value={hints.stone_type} onChange={(e) => setHints({ ...hints, stone_type: e.target.value })} placeholder="e.g. Red Aqeeq" />
          </Field>
          <Field label="Notes (optional)" className="sm:col-span-2">
            <Input value={hints.notes} onChange={(e) => setHints({ ...hints, notes: e.target.value })} placeholder="Anything the photo can't show (origin, weight, occasion)" />
          </Field>
          <Field
            label="Description prompt (optional)"
            help="Steer this generation — tone, length, angle. e.g. “Two short paragraphs, emphasise Yemeni heritage, no pricing talk.”"
            className="sm:col-span-2"
          >
            <Textarea
              value={instructions}
              onChange={(e) => setInstructions(e.target.value)}
              rows={2}
              placeholder="Leave blank to use the store's default brand voice."
            />
          </Field>
          <Field label="Language">
            <Select value={language} onChange={(e) => setLanguage(e.target.value)}>
              <option value="">Store default</option>
              <option value="both">Bilingual (EN + AR)</option>
              <option value="en">English</option>
              <option value="ar">Arabic</option>
            </Select>
          </Field>
        </div>

        {/* One-click full pipeline — opt-in. Nothing here affects this generation; it takes effect
            only after "Create draft product" succeeds, chaining against the new draft's id. */}
        <div className="flex flex-col gap-2 border-t border-edge pt-4">
          <span className="yz-label">After the draft is created</span>
          <Checkbox
            label="Auto-generate SEO"
            help="Runs the SEO pipeline against the new draft product."
            checked={autopilot.seo}
            onChange={(v) => patchAutopilot({ seo: v })}
          />
          <Checkbox
            label="Auto-generate marketing"
            help="Runs the marketing pipeline for the channels you pick."
            checked={autopilot.marketing}
            onChange={(v) => patchAutopilot({ marketing: v })}
          />
          {autopilot.marketing && (
            <div className="ms-6">
              <ChannelChips value={autopilot.channels} onToggle={toggleAutoChannel} />
              {autopilot.channels.length === 0 && (
                <p className="text-xs text-faint mt-1">
                  No channel selected — the store defaults will be used.
                </p>
              )}
            </div>
          )}
        </div>

        <Button variant="primary" onClick={generate} disabled={!media || busy}>
          {busy ? 'Working…' : 'Generate listing'}
        </Button>

        {busy && !result && <Spinner label="Reading the stone…" />}

        {result && (
          <div className="border-t border-edge pt-4 flex flex-col gap-5">
            <Meta meta={result.meta} />
            <p className="text-xs text-faint">Every field below is editable — tweak the text directly, then create the draft.</p>

            {/* FACTUAL — grounded in the photo/data */}
            <section className="border border-edge p-3 flex flex-col gap-3">
              <span className="yz-label">Factual · from the photo</span>
              {result.suggestion.observations && (
                <p className="text-sm text-fg italic">“{pickText(result.suggestion.observations)}”</p>
              )}
              <Attributes resolved={result.resolved} suggestion={result.suggestion} />
              <CategoryLine suggestion={result.suggestion} resolved={result.resolved} />
            </section>

            {/* CREATIVE — premium copy */}
            <section className="border border-edge p-3 flex flex-col gap-4">
              <span className="yz-label">Creative · premium copy</span>

              <div>
                <span className="yz-label">Name — pick one or edit</span>
                {Array.isArray(result.suggestion.name_suggestions) && result.suggestion.name_suggestions.length > 0 && (
                  <div className="flex flex-wrap gap-2 mt-1 mb-2">
                    {result.suggestion.name_suggestions.map((n) => (
                      <button
                        key={n}
                        type="button"
                        onClick={() => setChosenName(n)}
                        className={`yz-badge ${chosenName === n ? 'border-gold text-gold' : 'border-edge text-muted'}`}
                      >
                        {n}
                      </button>
                    ))}
                  </div>
                )}
                <Input value={chosenName} onChange={(e) => setChosenName(e.target.value)} placeholder="Final product name" />
              </div>

              <Bilingual label="Short description" value={edited.short_description} onChange={(v) => setField('short_description', v)} toast={toast} multiline />
              <Bilingual label="Description" value={edited.description_html} onChange={(v) => setField('description_html', v)} toast={toast} multiline />
              <Bilingual label="Story" value={edited.story} onChange={(v) => setField('story', v)} toast={toast} multiline />

              {Array.isArray(result.suggestion.product_highlights) && result.suggestion.product_highlights.length > 0 && (
                <div>
                  <span className="yz-label">Highlights</span>
                  <ul className="mt-1 flex flex-col gap-1">
                    {result.suggestion.product_highlights.map((h, i) => (
                      <li key={i} className="text-sm text-fg flex gap-2">
                        <span className="text-gold">•</span>
                        <span>{h}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {result.suggestion.customer_appeal_angle && (
                <Bilingual label="Customer appeal angle" value={edited.customer_appeal_angle} onChange={(v) => setField('customer_appeal_angle', v)} toast={toast} multiline />
              )}

              {result.suggestion.seo && (
                <>
                  <Bilingual label="SEO title" value={edited.seo?.title} onChange={(v) => setSeoField('title', v)} toast={toast} />
                  <Bilingual label="Meta description" value={edited.seo?.meta_description} onChange={(v) => setSeoField('meta_description', v)} toast={toast} multiline />
                </>
              )}

              <ChipList label="Keywords" items={result.suggestion.keywords} tone="gold" />
              <ChipList label="Tags" items={result.suggestion.tags} tone="muted" />

              {result.suggestion.marketing && (
                <div className="flex flex-col gap-3">
                  <span className="yz-label">Marketing</span>
                  <Bilingual label="Instagram" value={edited.marketing?.instagram} onChange={(v) => setMktField('instagram', v)} toast={toast} multiline />
                  <Bilingual label="Email subject" value={edited.marketing?.email_subject} onChange={(v) => setMktField('email_subject', v)} toast={toast} />
                  <Bilingual label="Ad" value={edited.marketing?.ad} onChange={(v) => setMktField('ad', v)} toast={toast} multiline />
                </div>
              )}
            </section>

            {/* Publish — the final step, after every input above */}
            <div className="flex flex-col gap-3 border-t border-edge pt-4">
              <Field label="Apply language" className="w-40">
                <Select value={applyLang} onChange={(e) => setApplyLang(e.target.value)}>
                  <option value="en">English</option>
                  <option value="ar">Arabic</option>
                </Select>
              </Field>
              <Button
                variant="primary"
                onClick={createDraft}
                disabled={creating || createdId > 0}
                className="w-full"
              >
                {creating ? 'Creating…' : createdId > 0 ? `Draft #${createdId} created ✓` : 'Create draft product →'}
              </Button>
            </div>
          </div>
        )}
      </div>
    </Card>
  )
}

/* ------------------------------------------------------------------ */
/* SEO + Marketing for an existing product                             */
/* ------------------------------------------------------------------ */

function ContentTools({ productId, chain }) {
  const toast = useToast()
  const [busy, setBusy] = useState('')
  const [seo, setSeo] = useState(null)
  const [marketing, setMarketing] = useState(null)
  const [mktChannels, setMktChannels] = useState(['instagram', 'facebook_ad', 'google_ads', 'email'])

  // Chained-run bookkeeping. The manual buttons keep toasting their errors exactly as before;
  // only chained steps (and their retries) report inline, so each one stays retryable on its own.
  const [progress, setProgress] = useState('') // '' | 'Generating SEO… 1/2'
  const [errors, setErrors] = useState({}) // { seo?: string, marketing?: string }
  const [chainCtx, setChainCtx] = useState(null) // { productId, channels } — what a Retry replays
  const ranToken = useRef(0)

  const toggleChannel = (key) =>
    setMktChannels((c) => (c.includes(key) ? c.filter((k) => k !== key) : [...c, key]))

  /**
   * The single generation code path — manual clicks, chained steps and retries all funnel here.
   * It RETURNS its outcome rather than presenting it, so each caller keeps its own presentation.
   */
  const runStep = async (kind, id, channels) => {
    setBusy(kind)
    try {
      const res = kind === 'seo' ? await aiApi.seo(id) : await aiApi.marketing(id, undefined, channels)
      // These endpoints answer non-2xx on failure (the client throws), so this guard is defensive.
      if (!res.ok) return { ok: false, error: res.error?.message || 'Generation failed.' }
      if (kind === 'seo') {
        setSeo(res)
      } else {
        setMarketing(res)
      }
      return { ok: true }
    } catch (err) {
      return { ok: false, error: err.message }
    } finally {
      setBusy('')
    }
  }

  const run = async (kind) => {
    const id = Number(productId)
    if (!id) {
      toast.error('Select a product above first.')
      return
    }
    setErrors((e) => ({ ...e, [kind]: null })) // a manual re-run supersedes a stale chained error
    const r = await runStep(kind, id, mktChannels)
    if (!r.ok) toast.error(r.error)
  }

  // Runs the steps the owner ticked in the photo card, in order, against the draft just created.
  // Deliberately no early break: a failed SEO must not stop marketing, and vice versa.
  const runChain = async (req) => {
    const steps = [req.seo && 'seo', req.marketing && 'marketing'].filter(Boolean)
    if (steps.length === 0) return
    setErrors({})
    setChainCtx({ productId: req.productId, channels: req.channels })
    if (req.marketing) setMktChannels(req.channels) // the card shows what the chain generated
    for (let i = 0; i < steps.length; i++) {
      const kind = steps[i]
      setProgress(`Generating ${STEP_LABEL[kind]}… ${i + 1}/${steps.length}`)
      const r = await runStep(kind, req.productId, req.channels)
      if (!r.ok) setErrors((e) => ({ ...e, [kind]: r.error }))
    }
    setProgress('')
  }

  // The token is claimed synchronously, so StrictMode's dev double-invoke can't fire the chain twice.
  useEffect(() => {
    if (!chain || chain.token === ranToken.current) return
    ranToken.current = chain.token
    runChain(chain)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chain])

  const retry = async (kind) => {
    const ctx = chainCtx || { productId: Number(productId), channels: mktChannels }
    if (!ctx.productId) return
    setErrors((e) => ({ ...e, [kind]: null }))
    setProgress(`Retrying ${STEP_LABEL[kind]}…`)
    const r = await runStep(kind, ctx.productId, ctx.channels)
    if (!r.ok) setErrors((e) => ({ ...e, [kind]: r.error }))
    setProgress('')
  }

  const running = Boolean(progress)

  return (
    <Card title="SEO &amp; marketing">
      <div className="flex items-center gap-2 mb-4">
        <Button
          small
          variant="primary"
          onClick={() => run('seo')}
          disabled={busy === 'seo' || running || !productId}
        >
          {busy === 'seo' ? '…' : 'Generate SEO'}
        </Button>
        <Button
          small
          variant="primary"
          onClick={() => run('marketing')}
          disabled={busy === 'marketing' || running || !productId}
        >
          {busy === 'marketing' ? '…' : 'Generate marketing'}
        </Button>
        {!productId && <span className="text-xs text-faint">Select a product above.</span>}
      </div>

      <div className="mb-4">
        <span className="yz-label">Marketing channels</span>
        <ChannelChips value={mktChannels} onToggle={toggleChannel} disabled={running} />
      </div>

      {running && (
        <div className="mb-4">
          <Spinner label={progress} />
        </div>
      )}

      {/* A chained step that failed reports here, retryable on its own — the draft product and the
          other step are untouched. */}
      {['seo', 'marketing'].map((kind) =>
        errors[kind] ? (
          <Alert
            key={kind}
            tone="danger"
            title={`${STEP_TITLE[kind]} generation failed`}
            onRetry={running ? undefined : () => retry(kind)}
            className="mb-4"
          >
            {errors[kind]}
          </Alert>
        ) : null,
      )}

      {seo && (
        <div className="border-t border-edge pt-4 flex flex-col gap-3 mb-4">
          <Meta meta={seo.meta} />
          <Bilingual label="SEO title" value={seo.suggestion.title} toast={toast} />
          <Bilingual label="Meta description" value={seo.suggestion.meta_description} toast={toast} multiline />
          <ChipList label="Focus keywords" items={seo.suggestion.focus_keywords} tone="gold" />
          <Bilingual label="Image alt text" value={seo.suggestion.alt_text} toast={toast} />
          <Bilingual label="Search-friendly copy" value={seo.suggestion.search_copy} toast={toast} multiline />
          <ChipList label="Tags" items={seo.suggestion.tags} tone="muted" />
          {Array.isArray(seo.suggestion.internal_links) && seo.suggestion.internal_links.length > 0 && (
            <div>
              <span className="yz-label">Internal links</span>
              <ul className="mt-1 flex flex-col gap-1">
                {seo.suggestion.internal_links.map((l) => (
                  <li key={l.product_id} className="text-sm flex gap-2">
                    <span className="text-gold">↗</span>
                    <a href={l.url} target="_blank" rel="noreferrer" className="text-fg hover:text-agate-fg transition-colors">
                      {pickText(l.anchor) || l.name}
                    </a>
                    <span className="text-faint text-xs">→ {l.name}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {seo.suggestion.slug && (
            <p className="text-xs text-faint">Slug: <span className="font-mono">{seo.suggestion.slug}</span></p>
          )}
        </div>
      )}

      {marketing && (
        <div className="border-t border-edge pt-4 flex flex-col gap-4">
          <Meta meta={marketing.meta} />
          {(() => {
            const m = marketing.suggestion
            return (
              <>
                {m.instagram && (
                  <div className="flex flex-col gap-2">
                    <Bilingual label="Instagram caption" value={m.instagram.caption} toast={toast} multiline />
                    {Array.isArray(m.instagram.hashtags) && m.instagram.hashtags.length > 0 && (
                      <div className="flex flex-wrap gap-1.5">
                        {m.instagram.hashtags.map((h) => (
                          <span key={h} className="text-xs text-gold">{h}</span>
                        ))}
                      </div>
                    )}
                  </div>
                )}
                {m.story && <Bilingual label="Story" value={m.story.text} toast={toast} multiline />}
                {m.facebook_ad && (
                  <div className="flex flex-col gap-2">
                    <Bilingual label="Facebook ad headline" value={m.facebook_ad.headline} toast={toast} />
                    <Bilingual label="Facebook ad text" value={m.facebook_ad.primary_text} toast={toast} multiline />
                  </div>
                )}
                {m.google_ads && (
                  <div className="flex flex-col gap-2">
                    <ChipList label="Google Ads headlines (≤30)" items={m.google_ads.headlines} tone="muted" />
                    <ChipList label="Google Ads descriptions (≤90)" items={m.google_ads.descriptions} tone="muted" />
                  </div>
                )}
                {m.email && (
                  <div className="flex flex-col gap-2">
                    <Bilingual label="Email subject" value={m.email.subject} toast={toast} />
                    <Bilingual label="Email body" value={m.email.body} toast={toast} multiline />
                  </div>
                )}
                {m.promo && <Bilingual label="Promotional text" value={m.promo.text} toast={toast} multiline />}
                {m.launch && <Bilingual label="Launch announcement" value={m.launch.announcement} toast={toast} multiline />}
                {m.retargeting && <Bilingual label="Retargeting message" value={m.retargeting.text} toast={toast} multiline />}
              </>
            )
          })()}
        </div>
      )}

      {!seo && !marketing && !running && !errors.seo && !errors.marketing && (
        <p className="text-sm text-muted">
          Select a product above, then generate refined, on-brand SEO metadata or marketing copy.
        </p>
      )}
    </Card>
  )
}

/* ------------------------------------------------------------------ */
/* Shared bits                                                         */
/* ------------------------------------------------------------------ */

function Attributes({ resolved, suggestion }) {
  const attrs = suggestion.attributes || {}
  const keys = Object.keys(attrs)
  if (keys.length === 0) return null
  const ids = resolved?.ids || {}

  return (
    <div>
      <span className="yz-label">Detected attributes</span>
      <div className="flex flex-wrap gap-2 mt-1">
        {keys.map((k) => {
          const val = attrs[k]
          if (!val) return null
          const matched = Object.prototype.hasOwnProperty.call(ids, k)
          return (
            <span key={k} className={`yz-badge ${matched ? 'border-ok text-ok' : 'border-warn text-warn'}`} title={matched ? 'Matched a store term' : 'No matching term — set manually'}>
              {val}
            </span>
          )
        })}
      </div>
      <p className="text-xs text-faint mt-1.5">
        Green attributes matched a store term and will be applied to the product; amber ones need manual selection.
      </p>
    </div>
  )
}

function CategoryLine({ suggestion, resolved }) {
  const label = suggestion.category
  if (!label) return null
  const matched = Boolean(resolved?.category)
  return (
    <div className="flex items-center gap-2">
      <span className="yz-label">Category</span>
      <span
        className={`yz-badge ${matched ? 'border-ok text-ok' : 'border-warn text-warn'}`}
        title={matched ? 'Matched a store category — applied on save' : 'No matching store category — set manually'}
      >
        {label}
      </span>
    </div>
  )
}

function ChipList({ label, items, tone }) {
  if (!Array.isArray(items) || items.length === 0) return null
  return (
    <div>
      <span className="yz-label">{label}</span>
      <div className="flex flex-wrap gap-2 mt-1">
        {items.map((t) => (
          <Badge key={t} tone={tone}>
            {t}
          </Badge>
        ))}
      </div>
    </div>
  )
}

function pickText(value) {
  if (value == null) return ''
  if (typeof value === 'string') return value
  if (typeof value === 'object') return value.en || value.ar || ''
  return ''
}

function Bilingual({ label, value, toast, multiline, onChange }) {
  const isObj = typeof value === 'object' && value !== null
  const en = isObj ? value.en || '' : typeof value === 'string' ? value : ''
  const ar = isObj ? value.ar || '' : ''
  const editable = typeof onChange === 'function'

  // Editing English replaces the string form (monolingual) or the .en of the bilingual object.
  const setEn = (t) => onChange(isObj ? { ...value, en: t } : t)
  const setAr = (t) => onChange(isObj ? { ...value, ar: t } : { en, ar: t })

  return (
    <div>
      <span className="yz-label">{label}</span>
      <div className="flex flex-col gap-2 mt-1">
        {(editable || en !== '') && (
          <CopyBlock text={en} toast={toast} multiline={multiline} onChange={editable ? setEn : undefined} />
        )}
        {ar !== '' && (
          <CopyBlock text={ar} toast={toast} multiline={multiline} rtl onChange={editable ? setAr : undefined} />
        )}
      </div>
    </div>
  )
}

function CopyBlock({ text, toast, multiline, rtl, onChange }) {
  const canEdit = typeof onChange === 'function'
  const [editing, setEditing] = useState(false) // read-only until the owner clicks Edit
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text)
      toast.success('Copied.')
    } catch {
      toast.error('Copy failed.')
    }
  }
  return (
    <div className="flex gap-2 items-start">
      {multiline ? (
        <Textarea
          readOnly={!editing}
          value={text}
          onChange={editing ? (e) => onChange(e.target.value) : undefined}
          rows={Math.min(6, Math.max(2, Math.ceil((text.length || 1) / 60)))}
          dir={rtl ? 'rtl' : 'ltr'}
        />
      ) : (
        <Input
          readOnly={!editing}
          value={text}
          onChange={editing ? (e) => onChange(e.target.value) : undefined}
          dir={rtl ? 'rtl' : 'ltr'}
        />
      )}
      {canEdit && (
        <Button
          small
          variant="ghost"
          onClick={() => setEditing((v) => !v)}
          title={editing ? 'Done editing' : 'Edit'}
        >
          {editing ? '✓' : '✎'}
        </Button>
      )}
      <Button small variant="ghost" onClick={copy} title="Copy">
        ⧉
      </Button>
    </div>
  )
}

function Meta({ meta }) {
  if (!meta) return null
  return (
    <div className="flex flex-wrap items-center gap-2 text-xs text-faint">
      <Badge tone={meta.cached ? 'muted' : 'gold'}>{meta.cached ? 'cached' : meta.provider}</Badge>
      {meta.model && <span className="font-mono">{meta.model}</span>}
      {meta.usage && (meta.usage.in || meta.usage.out) ? (
        <span>· {Number(meta.usage.in) + Number(meta.usage.out)} tokens · ${Number(meta.usage.cost || 0).toFixed(4)}</span>
      ) : null}
    </div>
  )
}

function pick(value, lang) {
  if (value == null) return ''
  if (typeof value === 'string') return value
  if (typeof value === 'object') return value[lang] || value.en || value.ar || ''
  return ''
}
