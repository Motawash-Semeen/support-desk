# Enums

PHP backed enums for fixed vocabularies: user role, ticket status, ticket priority,
activity type, reply type. Cast on models so the rest of the app never handles raw strings.
Enums may carry small presentation helpers (`label()`, `color()`) and transition rules.
