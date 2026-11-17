<?php

namespace Realmwatch\Viti\Console\Concerns;

/** For Viti's commands: add values to .env without ever changing one that's set. */
trait WritesEnv
{
    /**
     * Adds each key that's missing or empty, and keeps any value already
     * there. Returns the values now in effect.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function writeEnv(array $values): array
    {
        $file = $this->laravel->environmentFilePath();
        if (! is_file($file)) {
            $this->components->warn("No {$file}. Add these yourself:");
            foreach ($values as $key => $value) {
                $this->line("{$key}={$value}");
            }

            return $values;
        }

        $env = (string) file_get_contents($file);
        $original = $env;
        foreach ($values as $key => $value) {
            if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $m)) {
                $current = trim($m[1], " \t\r\"'");
                if ($current !== '') {
                    $values[$key] = $current;
                    $this->components->twoColumnDetail($key, 'already set, kept');

                    continue;
                }
                $env = str_replace($m[0], "{$key}={$value}", $env);
            } else {
                $env = rtrim($env, "\n")."\n{$key}={$value}\n";
            }
            $this->components->twoColumnDetail($key, 'written to .env');
        }
        if ($env !== $original) {
            file_put_contents($file, $env);
        }

        return $values;
    }
}
