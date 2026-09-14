<?php

namespace App\Console\Commands\Concerns;

/**
 * Shared by tournaments:backfill-global-catalog and
 * tournaments:verify-global-catalog -- verification has to be given the
 * SAME alias/ages files a real run used, or it would flag every aliased
 * club as "missing", or an applied age range as a phantom pending change.
 *
 * Both --aliases and --ages are the same shape on disk: a JSON object of
 * {"<user_id>": {...per-organizer data confirmed by a human...}}. This
 * trait loads either given the CLI option name to read it from.
 */
trait LoadsClubNameAliases
{
    /**
     * @return array<int, array<string, string>>|null null means "abort" (a bad --aliases path/JSON was given)
     */
    private function loadClubNameAliases(): ?array
    {
        return $this->loadPerOrganizerJsonOption('aliases', 'alias');
    }

    /**
     * @return array<int, array<string, array{birth_year_from?: int|null, birth_year_to?: int|null}>>|null null means "abort" (a bad --ages path/JSON was given)
     */
    private function loadAgeRanges(): ?array
    {
        return $this->loadPerOrganizerJsonOption('ages', 'rangos de edad');
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function loadPerOrganizerJsonOption(string $option, string $label): ?array
    {
        $path = $this->option($option);
        if (! $path) {
            return [];
        }

        if (! is_file($path)) {
            $this->error("No existe el archivo de {$label}: {$path}");

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            $this->error("El archivo de {$label} no es JSON válido: {$path}");

            return null;
        }

        $normalized = [];
        foreach ($decoded as $userId => $map) {
            $normalized[(int) $userId] = (array) $map;
        }

        return $normalized;
    }
}
