<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->category = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Groceries',
        'type' => 'expense',
    ]);
});

test('unauthenticated users cannot preview or import CSV files', function () {
    $file = UploadedFile::fake()->createWithContent('test.csv', "date,amount,description,category,type\n2026-01-01,10.00,Bread,Groceries,expense\n");

    $this->postJson('/api/import/preview', ['file' => $file])
        ->assertUnauthorized();

    $this->postJson('/api/import', ['file' => $file, 'confirm' => true])
        ->assertUnauthorized();
});

test('preview successfully validates a standard expense and income CSV', function () {
    $csv = "Date,Amount,Description,Category,Type\n"
        . "2026-02-01,50.25,Supermarket,Groceries,expense\n"
        . "2026-02-02,1500.00,Client Freelance,,income\n";

    $file = UploadedFile::fake()->createWithContent('transactions.csv', $csv);

    $response = $this->actingAs($this->user)
        ->postJson('/api/import/preview', ['file' => $file])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_rows' => 2,
                    'valid_rows' => 2,
                    'error_rows' => 0,
                    'duplicate_rows' => 0,
                    'expense_count' => 1,
                    'income_count' => 1,
                ],
            ],
        ]);

    $data = $response->json('data');
    expect($data['valid'])->toHaveCount(2);
    expect($data['preview_rows'])->toHaveCount(2);
    expect($data['preview_rows'][0]['status'])->toBe('valid');
    expect($data['preview_rows'][0]['description'])->toBe('Supermarket');
    expect($data['preview_rows'][1]['description'])->toBe('Client Freelance');
});

test('file validation rejects empty files, unsupported extensions, and oversized files', function () {
    // 1. Empty file
    $empty = UploadedFile::fake()->createWithContent('empty.csv', '');
    $this->actingAs($this->user)
        ->postJson('/api/import/preview', ['file' => $empty])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);

    // 2. Unsupported extension (.pdf)
    $pdf = UploadedFile::fake()->createWithContent('evil.pdf', 'fake-binary');
    $this->actingAs($this->user)
        ->postJson('/api/import/preview', ['file' => $pdf])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);

    // 3. Oversized file (> 2 MB)
    $large = UploadedFile::fake()->create('large.csv', 2500); // 2.5 MB
    $this->actingAs($this->user)
        ->postJson('/api/import/preview', ['file' => $large])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

test('preview rejects files with missing required columns or duplicate headers', function () {
    // Missing amount column
    $noAmount = UploadedFile::fake()->createWithContent('test.csv', "date,description,category\n2026-01-01,Test,Groceries\n");
    $this->actingAs($this->user)
        ->postJson('/api/import/preview', ['file' => $noAmount])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);

    // Duplicate headers
    $dupHeaders = UploadedFile::fake()->createWithContent('test.csv', "date,amount,amount,category\n2026-01-01,10.00,10.00,Groceries\n");
    $this->actingAs($this->user)
        ->postJson('/api/import/preview', ['file' => $dupHeaders])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

test('handles utf-8 byte order mark and alternative delimiters cleanly', function () {
    $semicolonCsv = Csv::BOM . "Date;Amount;Description;Category;Type\r\n"
        . "2026-03-01;25.50;Market;Groceries;expense\r\n";

    $file = UploadedFile::fake()->createWithContent('bom_semicolon.csv', $semicolonCsv);

    $response = $this->actingAs($this->user)
        ->postJson('/api/import/preview', ['file' => $file])
        ->assertOk();

    expect($response->json('data.summary.valid_rows'))->toBe(1);
    expect($response->json('data.valid.0.amount'))->toBe('25.50');
});

test('supports custom column mapping when CSV headers are non-standard', function () {
    $csv = "Posted,Debit,Store,Tag\n"
        . "2026-03-15,42.00,Corner Store,Groceries\n";

    $file = UploadedFile::fake()->createWithContent('nonstandard.csv', $csv);

    $response = $this->actingAs($this->user)
        ->postJson('/api/import/preview', [
            'file' => $file,
            'mapping' => [
                'date' => 'Posted',
                'amount' => 'Debit',
                'description' => 'Store',
                'category' => 'Tag',
            ],
        ])
        ->assertOk();

    expect($response->json('data.summary.valid_rows'))->toBe(1);
    expect($response->json('data.valid.0.date'))->toBe('2026-03-15');
    expect($response->json('data.valid.0.amount'))->toBe('42.00');
    expect($response->json('data.valid.0.description'))->toBe('Corner Store');
    expect($response->json('data.valid.0.category_name'))->toBe('Groceries');
});

test('rejects files with excessive columns and flags excessive field lengths', function () {
    // Excessive columns (> 50)
    $manyCols = implode(',', array_map(fn ($i) => "col$i", range(1, 55))) . "\n";
    $manyCols .= implode(',', array_fill(0, 55, 'val')) . "\n";
    $fileManyCols = UploadedFile::fake()->createWithContent('cols.csv', $manyCols);

    $this->actingAs($this->user)
        ->postJson('/api/import/preview', ['file' => $fileManyCols])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);

    // Excessive field length (> 1000 characters)
    $longField = str_repeat('A', 1200);
    $csvLong = "date,amount,description,category,type\n"
        . "2026-01-01,10.00,{$longField},Groceries,expense\n";
    $fileLong = UploadedFile::fake()->createWithContent('long.csv', $csvLong);

    $response = $this->actingAs($this->user)
        ->postJson('/api/import/preview', ['file' => $fileLong])
        ->assertOk();

    expect($response->json('data.summary.error_rows'))->toBe(1);
    expect($response->json('data.errors.0.message'))->toContain('maximum length');
});

test('supports alternative date formats and normalizes amounts with currency symbols and commas', function () {
    $csv = "date,amount,description,category,type\n"
        . "15/04/2026,\"$1,250.75\",Rent,Groceries,expense\n"
        . "2026/05/20,\"₹5,000.00\",Freelance,,income\n";

    $file = UploadedFile::fake()->createWithContent('dates.csv', $csv);

    $response = $this->actingAs($this->user)
        ->postJson('/api/import/preview', ['file' => $file])
        ->assertOk();

    $valid = $response->json('data.valid');
    expect($valid)->toHaveCount(2);
    expect($valid[0]['date'])->toBe('2026-04-15');
    expect($valid[0]['amount'])->toBe('1250.75');
    expect($valid[1]['date'])->toBe('2026-05-20');
    expect($valid[1]['amount'])->toBe('5000.00');
});

test('row level errors report exact row numbers and messages for invalid fields', function () {
    $csv = "date,amount,description,category,type\n"
        . "not-a-date,10.00,Invalid Date,Groceries,expense\n"
        . "2026-01-01,-50.00,Negative Amount,Groceries,expense\n"
        . "2026-01-01,20.00,Unknown Cat,NonExistentCategory,expense\n"
        . "2026-01-01,30.00,Good Row,Groceries,expense\n";

    $file = UploadedFile::fake()->createWithContent('errors.csv', $csv);

    $response = $this->actingAs($this->user)
        ->postJson('/api/import/preview', ['file' => $file])
        ->assertOk();

    $summary = $response->json('data.summary');
    expect($summary['total_rows'])->toBe(4);
    expect($summary['valid_rows'])->toBe(1);
    expect($summary['error_rows'])->toBe(3);

    $errors = $response->json('data.errors');
    expect($errors)->toHaveCount(3);
    expect($errors[0]['row'])->toBe(2);
    expect($errors[0]['field'])->toBe('date');
    expect($errors[1]['row'])->toBe(3);
    expect($errors[1]['field'])->toBe('amount');
    expect($errors[2]['row'])->toBe(4);
    expect($errors[2]['field'])->toBe('category');
});

test('detects in-file duplicates and existing database records', function () {
    // Create pre-existing record in database
    Expense::create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'amount' => 99.00,
        'description' => 'Existing Transaction',
        'expense_date' => '2026-01-10',
    ]);

    $csv = "date,amount,description,category,type\n"
        . "2026-01-10,99.00,Existing Transaction,Groceries,expense\n" // Database duplicate
        . "2026-01-12,45.00,New Item,Groceries,expense\n"
        . "2026-01-12,45.00,New Item,Groceries,expense\n"; // In-file duplicate of row 3

    $file = UploadedFile::fake()->createWithContent('duplicates.csv', $csv);

    $response = $this->actingAs($this->user)
        ->postJson('/api/import/preview', ['file' => $file])
        ->assertOk();

    $duplicates = $response->json('data.duplicates');
    expect($duplicates)->toHaveCount(2);
    expect($duplicates[0]['message'])->toContain('Duplicate row in import');
    expect($duplicates[1]['message'])->toContain('already exists in your SpendSense records');
});

test('import confirmation commits valid records and honors duplicate skip policy', function () {
    // Create pre-existing record
    Expense::create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'amount' => 30.00,
        'description' => 'Already Imported',
        'expense_date' => '2026-01-05',
    ]);

    $csv = "date,amount,description,category,type\n"
        . "2026-01-05,30.00,Already Imported,Groceries,expense\n" // Duplicate
        . "2026-01-06,60.00,Fresh Expense,Groceries,expense\n"     // Valid
        . "2026-01-07,500.00,Bonus Salary,,income\n";             // Valid

    $file = UploadedFile::fake()->createWithContent('commit.csv', $csv);

    // 1. Without confirm: false, must fail validation
    $this->actingAs($this->user)
        ->postJson('/api/import', ['file' => $file, 'confirm' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirm']);

    // 2. With confirm: true and default skip policy
    $response = $this->actingAs($this->user)
        ->postJson('/api/import', [
            'file' => $file,
            'confirm' => true,
            'duplicate_policy' => 'skip',
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'imported' => 2,
                'skipped' => 1,
                'duplicates_skipped' => 1,
            ],
        ]);

    // Check database state
    expect(Expense::where('user_id', $this->user->id)->count())->toBe(2); // 1 existing + 1 imported
    expect(Income::where('user_id', $this->user->id)->count())->toBe(1);  // 1 imported

    $newExpense = Expense::where('user_id', $this->user->id)->where('description', 'Fresh Expense')->first();
    expect($newExpense)->not->toBeNull();
    expect((float)$newExpense->amount)->toBe(60.0);

    $newIncome = Income::where('user_id', $this->user->id)->where('source', 'Bonus Salary')->first();
    expect($newIncome)->not->toBeNull();
    expect((float)$newIncome->amount)->toBe(500.0);
});

test('import duplicate policy allows importing duplicates when explicitly chosen', function () {
    Expense::create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'amount' => 15.00,
        'description' => 'Repeat Coffee',
        'expense_date' => '2026-01-01',
    ]);

    $csv = "date,amount,description,category,type\n"
        . "2026-01-01,15.00,Repeat Coffee,Groceries,expense\n";

    $file = UploadedFile::fake()->createWithContent('allow_dup.csv', $csv);

    $this->actingAs($this->user)
        ->postJson('/api/import', [
            'file' => $file,
            'confirm' => true,
            'duplicate_policy' => 'import',
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'imported' => 1,
                'skipped' => 0,
            ],
        ]);

    expect(Expense::where('user_id', $this->user->id)->where('description', 'Repeat Coffee')->count())->toBe(2);
});

test('data isolation guarantees that other users records and categories are strictly isolated', function () {
    $otherUser = User::factory()->create();
    $otherCategory = Category::create([
        'user_id' => $otherUser->id,
        'name' => 'Secret Other Category',
        'type' => 'expense',
    ]);
    Expense::create([
        'user_id' => $otherUser->id,
        'category_id' => $otherCategory->id,
        'amount' => 100.00,
        'description' => 'Other User Expense',
        'expense_date' => '2026-01-01',
    ]);

    // Current user tries to import using other user's category
    $csv = "date,amount,description,category,type\n"
        . "2026-01-01,100.00,Other User Expense,Secret Other Category,expense\n";

    $file = UploadedFile::fake()->createWithContent('leak_test.csv', $csv);

    $response = $this->actingAs($this->user)
        ->postJson('/api/import/preview', ['file' => $file])
        ->assertOk();

    // Category must be rejected because it belongs to another user
    expect($response->json('data.summary.error_rows'))->toBe(1);
    expect($response->json('data.errors.0.message'))->toContain("does not exist in your available expense categories");
});

test('stored text sanitizes control characters and formula prefixes safely', function () {
    $csv = "date,amount,description,category,type\n"
        . "2026-01-01,20.00,\"=SUM(A1:A10)\x00evil\",Groceries,expense\n";

    $file = UploadedFile::fake()->createWithContent('formula.csv', $csv);

    $this->actingAs($this->user)
        ->postJson('/api/import', [
            'file' => $file,
            'confirm' => true,
        ])
        ->assertOk();

    $imported = Expense::where('user_id', $this->user->id)->first();
    expect($imported)->not->toBeNull();
    // Null byte stripped
    expect($imported->description)->not->toContain("\x00");
    expect($imported->description)->toBe("=SUM(A1:A10)evil");

    // When exported with Csv::cell(), it must be neutralized
    expect(Csv::cell($imported->description))->toBe("'=SUM(A1:A10)evil");
});
