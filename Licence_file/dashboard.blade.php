<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - License Demo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">License Demo Dashboard</a>
        </div>
    </nav>

    <div class="container mt-5">
        <h1 class="mb-4">License Verification Demo</h1>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Valid License</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-striped">
                            <tr>
                                <th>Product:</th>
                                <td>{{ $product }}</td>
                            </tr>
                            <tr>
                                <th>Domain:</th>
                                <td>{{ $domain }}</td>
                            </tr>
                            <tr>
                                <th>Expiry Date:</th>
                                <td>{{ $expiry }}</td>
                            </tr>
                            <tr>
                                <th>Max Users:</th>
                                <td>{{ $maxUsers }}</td>
                            </tr>
                            <tr>
                                <th>License Key:</th>
                                <td><code>{{ $licenseKey }}</code></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">How to Test</h5>
                    </div>
                    <div class="card-body">
                        <h6>Test Scenarios:</h6>
                        <ol>
                            <li><strong>No license file:</strong> Delete <code>license.key</code> to see "License file not found" error</li>
                            <li><strong>Domain mismatch:</strong> Change domain in <code>license.key</code> to test domain validation</li>
                            <li><strong>Expired license:</strong> Set expiry date to past date</li>
                            <li><strong>Invalid signature:</strong> Modify <code>license.key</code> data to test signature verification</li>
                        </ol>
                        <hr>
                        <p class="text-muted">This demo showcases the offline license verification system.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <a href="/" class="btn btn-secondary">Back to Home</a>
        </div>
    </div>
</body>
</html>
