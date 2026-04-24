Route::get('addhost/ip', [SystemBulkUploadController::class, 'addHostIp'])->name('addhost.ip');
    Route::post('addhost/ip/save', [SystemBulkUploadController::class, 'addHostIpsave'])->name('addhost.ip.save');
