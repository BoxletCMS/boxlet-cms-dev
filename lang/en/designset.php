<?php

// What a design set's reader says about a file it refused or trimmed (PLAN.md D-152).
return [
    'designset.too_large' => 'The file is larger than :size KB, which no design is.',
    'designset.too_deep' => 'The file is nested more than :depth levels deep, which no design is.',
    'designset.not_json' => 'This file is not a Boxlet design: it is not JSON.',
    'designset.not_a_design' => 'This file is not a Boxlet design.',
    'designset.version' => 'This design is format version :version, which this Boxlet cannot read. Update Boxlet, then import it again.',
    'designset.id' => 'id: a design needs an id of lowercase letters, digits and hyphens, at most 32, starting with a letter or digit.',
    'designset.name' => 'name: a design needs a name in at least one language.',
    'designset.field' => ':field: :reason',
    'designset.missing' => 'missing.',
    'designset.unknown_decision' => 'not a decision Boxlet has.',
    'designset.unknown_choice' => 'not a header or footer choice Boxlet has.',
    'designset.look_incomplete' => 'a character sets every header and footer choice, and this one is missing.',
    'designset.unknown_key' => 'Left out :key, which a Boxlet design does not have.',
    'designset.block_unknown' => 'Left out :field: this site has no such block.',
    'designset.layout_unknown' => 'Left out :field: that block does not offer this layout.',
];
