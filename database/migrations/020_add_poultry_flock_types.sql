ALTER TABLE flocks
    MODIFY COLUMN flock_type ENUM(
        'broiler',
        'layer',
        'cockerel',
        'free_range',
        'breeder',
        'quail',
        'turkey',
        'duck',
        'guinea_fowl',
        'goose',
        'pigeon'
    ) NOT NULL DEFAULT 'broiler';

ALTER TABLE flocks
    MODIFY COLUMN poultry_type ENUM(
        'chicken',
        'turkey',
        'duck',
        'quail',
        'guinea_fowl',
        'goose',
        'pigeon',
        'other'
    ) NOT NULL DEFAULT 'chicken';

ALTER TABLE farm_cycle_settings
    MODIFY COLUMN poultry_type ENUM(
        'chicken',
        'turkey',
        'duck',
        'quail',
        'guinea_fowl',
        'goose',
        'pigeon',
        'other'
    ) NOT NULL;

INSERT IGNORE INTO system_reference_items
    (reference_type, code, label, position)
VALUES
    ('flock_type', 'quail', 'Caille', 60),
    ('flock_type', 'turkey', 'Dinde', 70),
    ('flock_type', 'duck', 'Canard', 80),
    ('flock_type', 'guinea_fowl', 'Pintade', 90),
    ('flock_type', 'goose', 'Oie', 100),
    ('flock_type', 'pigeon', 'Pigeon', 110);

INSERT IGNORE INTO system_reference_items
    (reference_type, code, label, position)
VALUES
    ('poultry_type', 'quail', 'Caille', 40),
    ('poultry_type', 'guinea_fowl', 'Pintade', 50),
    ('poultry_type', 'goose', 'Oie', 60),
    ('poultry_type', 'pigeon', 'Pigeon', 70);
