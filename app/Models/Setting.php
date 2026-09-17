<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Singleton settings row (always id 1) for global feature switches an
 * admin can flip without a code change/deploy -- e.g. PDF uploads on
 * sanctions, off by default because production currently runs on a
 * storage-limited server (see sanction_pdf_uploads_enabled). Every call
 * site reads through a small static helper like
 * sanctionPdfUploadsEnabled() rather than touching current() directly, so
 * a future flag only needs a new column + a new helper here.
 *
 * @property int $id
 * @property bool $sanction_pdf_uploads_enabled
 */
#[Fillable(['sanction_pdf_uploads_enabled'])]
class Setting extends Model
{
    protected function casts(): array
    {
        return [
            'sanction_pdf_uploads_enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], ['sanction_pdf_uploads_enabled' => false]);
    }

    public static function sanctionPdfUploadsEnabled(): bool
    {
        return static::current()->sanction_pdf_uploads_enabled;
    }
}
