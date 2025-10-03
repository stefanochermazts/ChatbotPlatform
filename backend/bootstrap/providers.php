<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\BroadcastServiceProvider::class,
    // HorizonServiceProvider caricato condizionalmente in base all'ambiente
    App\Providers\HorizonServiceProvider::class,
];
