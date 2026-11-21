Migrations that MigrationSafetyTest reads as text, never runs: what tells a migration that only adds to the schema from
one that takes a column or a table away, with and without the annotation of the release from which that step is safe.
They end in .stub so that neither the application nor the static analysis loads them.
