<?php

namespace App\Modules\Construction\Models;

use App\Models\TenantModel as Model;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use App\Traits\HasComments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One information container in the ISO 19650 register — `docs/construction-management-plan.md` §15.
 *
 * The four states are the standard's: **work in progress → shared → published → archived**, with an approval
 * gate between shared and published. The transitions are enforced in `DocumentStateMachine`, never here and
 * never in a form — §15 says so, and the reason is that a form-level rule is bypassed by every other caller
 * (an import, a command, a transmittal action) and nothing reports it.
 *
 * **Published is the only state a construction-issue drawing may be in.** An inspection, an RFI answer or a
 * punch item referencing a work-in-progress drawing is a defect waiting to be built.
 */
class Document extends Model
{
    use Auditable;
    use HasComments;

    public const STATE_WIP = 'work_in_progress';

    public const STATE_SHARED = 'shared';

    public const STATE_PUBLISHED = 'published';

    public const STATE_ARCHIVED = 'archived';

    /** In order, because the machine only ever moves forward or to archived. */
    public const STATES = [
        self::STATE_WIP => 'Work in progress',
        self::STATE_SHARED => 'Shared',
        self::STATE_PUBLISHED => 'Published',
        self::STATE_ARCHIVED => 'Archived',
    ];

    /**
     * A container the application stores and never opens — §15.1.
     *
     * The type exists so the register is *complete*: the IFC or RVT is held with its metadata and its hash and
     * people download it into whatever viewer they already own. Naming it here is what lets the interface say
     * so rather than showing a broken preview.
     */
    public const TYPE_MODEL = 'model';

    /**
     * Who may read it. `normal` is anybody who may read documents; `restricted` — a tender build-up, an
     * incident investigation naming an injured person — needs ConstructionDocumentRestrictedView as well,
     * and the file streams to nobody else (DocumentPolicy::view, enforced through TenantFileAccess).
     */
    public const CONFIDENTIALITY_NORMAL = 'normal';

    public const CONFIDENTIALITY_RESTRICTED = 'restricted';

    protected $table = 'construction_documents';

    protected $fillable = [
        'job_id', 'information_container_id',
        'project_code', 'originator_code', 'functional_code', 'spatial_code',
        'form_code', 'discipline_code', 'container_number',
        'naming_convention_id', 'title', 'description', 'document_type', 'cde_state',
        'current_revision_id', 'originator_contact_id', 'wbs_node_id', 'location_id',
        'is_contractual', 'confidentiality', 'superseded_by_document_id',
    ];

    protected $casts = [
        'is_contractual' => 'boolean',
    ];

    protected $attributes = [
        'document_type' => 'drawing',
        'cde_state' => self::STATE_WIP,
        'is_contractual' => false,
        'confidentiality' => 'normal',
    ];

    public function isRestricted(): bool
    {
        return $this->confidentiality === self::CONFIDENTIALITY_RESTRICTED;
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function namingConvention(): BelongsTo
    {
        return $this->belongsTo(NamingConvention::class, 'naming_convention_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class, 'document_id')->orderByDesc('id');
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'current_revision_id');
    }

    public function originator(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'originator_contact_id');
    }

    public function wbsNode(): BelongsTo
    {
        return $this->belongsTo(WbsNode::class, 'wbs_node_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * The identifier assembled from the naming fields, in the convention's own order and separator.
     *
     * Derived here and *stored* in `information_container_id` on save, because it is searched, quoted on
     * transmittals and printed — re-assembling it at every read would be seven string operations on every row
     * of a register with thousands in it.
     */
    public function assembleIdentifier(?NamingConvention $convention = null): string
    {
        $convention ??= $this->namingConvention;

        $fields = $convention?->fields() ?? NamingConvention::FIELDS;
        $separator = $convention?->separator ?: '-';

        $parts = array_filter(
            array_map(fn (string $field): ?string => $this->{$field}, $fields),
            fn (?string $value): bool => filled($value),
        );

        return implode($separator, $parts);
    }

    /** What may be issued for construction: §15's hardest rule, and the one with a defect behind it. */
    public function isIssuableForConstruction(): bool
    {
        return $this->cde_state === self::STATE_PUBLISHED;
    }

    public function isModel(): bool
    {
        return $this->document_type === self::TYPE_MODEL;
    }

    /**
     * Whether the file may be previewed in the browser at all.
     *
     * PDFs and images render natively in an iframe, which is free; a model never opens and the interface says
     * so rather than showing a viewer that fails. §15.1 draws exactly this line.
     */
    public function isPreviewable(): bool
    {
        if ($this->isModel()) {
            return false;
        }

        $mime = $this->currentRevision?->file_mime;

        return $mime !== null && (str_starts_with($mime, 'image/') || $mime === 'application/pdf');
    }

    public function scopeInState(Builder $query, string $state): Builder
    {
        return $query->where('cde_state', $state);
    }

    /** The register as somebody working off drawings needs it: published, and not superseded. */
    public function scopeIssuable(Builder $query): Builder
    {
        return $query->where('cde_state', self::STATE_PUBLISHED)->whereNull('superseded_by_document_id');
    }
}
