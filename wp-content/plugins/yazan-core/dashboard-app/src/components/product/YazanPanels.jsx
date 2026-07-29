import { useMeta, findAttribute } from '../../context/MetaContext.jsx'
import { boot } from '../../api/client.js'
import { AttachmentField } from '../media/ImageFields.jsx'
import { Badge, Card, Field, Input, Select, Textarea } from '../ui.jsx'

const GROUP_LABELS = {
  agate: 'The stone',
  silver: 'The silver',
  ring: 'The ring',
}

/**
 * Jewelry panel — every select here writes to a REAL WooCommerce global attribute
 * (pa_stone, pa_origin, pa_metal, pa_size, …), which is what the storefront eyebrow,
 * filters, and the /verify-ring/ certificate already read. Nothing is duplicated.
 */
export function JewelryPanel({ form, set }) {
  const { meta } = useMeta()
  const fields = meta?.jewelry_fields || []
  const jewelry = form.jewelry || {}

  const setAttribute = (key, termId) =>
    set('jewelry', {
      ...jewelry,
      attributes: { ...jewelry.attributes, [key]: Number(termId) || 0 },
    })

  const groups = ['agate', 'silver', 'ring'].filter((group) =>
    fields.some((field) => field.group === group),
  )

  return (
    <Card title="Jewelry information">
      <div className="space-y-5">
        {groups.map((group) => (
          <div key={group}>
            <h3 className="text-[11px] uppercase tracking-[0.16em] text-gold mb-3">
              {GROUP_LABELS[group]}
            </h3>
            <div className="grid sm:grid-cols-2 gap-4">
              {fields
                .filter((field) => field.group === group)
                .map((field) => {
                  const attribute = findAttribute(meta, field.taxonomy)
                  return (
                    <Field key={field.key} label={field.label}>
                      <Select
                        value={jewelry.attributes?.[field.key] || 0}
                        onChange={(event) => setAttribute(field.key, event.target.value)}
                        disabled={!attribute}
                      >
                        <option value={0}>— Not set —</option>
                        {(attribute?.terms || []).map((term) => (
                          <option key={term.id} value={term.id}>
                            {term.name}
                          </option>
                        ))}
                      </Select>
                      {!attribute && (
                        <p className="yz-help">
                          Attribute <code>{field.taxonomy}</code > not found in WooCommerce.
                        </p>
                      )}
                    </Field>
                  )
                })}

              {group === 'silver' && (
                <Field label="Silver weight" help="Free text, e.g. “8.4 g”.">
                  <Input
                    value={jewelry.silver_weight || ''}
                    onChange={(event) => set('jewelry', { ...jewelry, silver_weight: event.target.value })}
                  />
                </Field>
              )}
            </div>
          </div>
        ))}

        <div className="pt-4 border-t border-edge grid sm:grid-cols-2 gap-4">
          <Field label="Collection">
            <Select
              value={jewelry.collection || 0}
              onChange={(event) => set('jewelry', { ...jewelry, collection: Number(event.target.value) })}
            >
              <option value={0}>— Not set —</option>
              {(meta?.collections || []).map((term) => (
                <option key={term.id} value={term.id}>
                  {term.name}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        <Field label="Craftsmanship details" help="Making notes — setting, finish, hand-work.">
          <Textarea
            rows={3}
            value={jewelry.craftsmanship || ''}
            onChange={(event) => set('jewelry', { ...jewelry, craftsmanship: event.target.value })}
          />
        </Field>
      </div>
    </Card>
  )
}

/**
 * Authenticity panel — the authoring UI for the existing /verify-ring/{serial}/ feature.
 * Setting a serial and marking the ring "certified" is what makes it resolve publicly.
 * Scope is authenticity only: no owner or ownership data is stored anywhere.
 */
export function AuthenticityPanel({ form, set }) {
  const jewelry = form.jewelry || {}
  const serial = jewelry.serial || ''
  const status = jewelry.verification_status || 'draft'
  const verifyUrl = serial ? `${boot.homeUrl}verify-ring/${encodeURIComponent(serial)}/` : ''

  const patch = (changes) => set('jewelry', { ...jewelry, ...changes })

  return (
    <Card
      title="Authentication"
      actions={
        status === 'certified' && serial ? (
          <Badge tone="gold">Certified</Badge>
        ) : (
          <Badge>{status}</Badge>
        )
      }
    >
      <div className="space-y-4">
        <div className="grid sm:grid-cols-2 gap-4">
          <Field
            label="Ring serial number"
            help="Uppercase letters, numbers and hyphens, e.g. YZ-925-2026-00147."
          >
            <Input
              value={serial}
              onChange={(event) => patch({ serial: event.target.value.toUpperCase() })}
              placeholder="YZ-925-2026-00000"
              className="font-mono"
            />
          </Field>

          <Field label="Verification status" help="Only “certified” rings resolve on the public verify page.">
            <Select value={status} onChange={(event) => patch({ verification_status: event.target.value })}>
              <option value="draft">Draft — not yet certified</option>
              <option value="certified">Certified — publicly verifiable</option>
              <option value="retired">Retired — no longer active</option>
            </Select>
          </Field>
        </div>

        {verifyUrl && (
          <div className="border border-edge p-3 flex flex-wrap items-center gap-3 justify-between">
            <div className="min-w-0">
              <span className="yz-label mb-0.5">Public verification link</span>
              <a
                href={verifyUrl}
                target="_blank"
                rel="noreferrer"
                className="text-sm text-gold hover:underline break-all"
              >
                {verifyUrl}
              </a>
            </div>
            <button
              type="button"
              onClick={() => navigator.clipboard?.writeText(verifyUrl)}
              className="yz-btn yz-btn-ghost yz-btn-sm"
            >
              Copy
            </button>
          </div>
        )}

        <div className="grid sm:grid-cols-2 gap-4 pt-2 border-t border-edge">
          <AttachmentField
            label="QR code image"
            value={jewelry.qr_id}
            url={jewelry.qr_url}
            onChange={(id, url) => patch({ qr_id: id, qr_url: url })}
          />
          <AttachmentField
            label="Digital certificate (image or PDF)"
            value={jewelry.certificate_id}
            url={jewelry.certificate_url}
            onChange={(id, url) => patch({ certificate_id: id, certificate_url: url })}
          />
        </div>

        <p className="text-xs text-faint">
          Generate the QR from the verification link above and upload it here. Authenticity data
          covers the stone, silver and craft only — never ownership.
        </p>
      </div>
    </Card>
  )
}
