<?php

namespace App\Models\Concerns;

/**
 * Uppercases a model's own identity fields (club/team/category/player/DT/
 * referee/tournament/group/phase names) right before every save, so
 * listings, brackets and the public portal read consistently no matter how
 * each one was originally typed -- instead of relying on every view
 * remembering to transform it for display. Deliberately never applied to
 * optional/free-text fields (Category::$description, Sanction's
 * resolution_notes, and the like) -- those keep whatever case the organizer
 * actually typed.
 *
 * A consuming model declares which of its own attributes this applies to
 * via $uppercaseAttributes, e.g. `protected array $uppercaseAttributes =
 * ['full_name'];`.
 */
trait NormalizesToUppercase
{
    public static function bootNormalizesToUppercase(): void
    {
        static::saving(function (self $model): void {
            foreach ($model->uppercaseAttributeNames() as $attribute) {
                if ($model->{$attribute} !== null) {
                    $model->{$attribute} = mb_strtoupper($model->{$attribute});
                }
            }
        });
    }

    /**
     * @return list<string>
     */
    public function uppercaseAttributeNames(): array
    {
        return $this->uppercaseAttributes;
    }
}
