<?php

// Framework-internal dispatch remains synchronous; the application has no job worker.
return ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']], 'failed' => ['driver' => 'null']];
