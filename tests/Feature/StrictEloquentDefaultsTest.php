<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;

beforeEach(function (): void {
    Schema::create('strict_parents', function (Blueprint $table): void {
        $table->id();
    });

    Schema::create('strict_children', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('strict_parent_id');
    });
});

/**
 * Eloquent only arms the lazy loading guard when a query hydrates more than one row, so
 * an N+1 test must fetch a collection.
 */
function createParentsWithChildren(int $count): void
{
    foreach (range(1, $count) as $ignored) {
        StrictChild::create(['strict_parent_id' => StrictParent::create()->id]);
    }
}

it('prevents lazy loading outside production', function (): void {
    createParentsWithChildren(2);

    $parents = StrictParent::query()->get();

    expect(fn (): mixed => $parents->firstOrFail()->children->first())
        ->toThrow(LazyLoadingViolationException::class);
});

it('allows eager loaded relations', function (): void {
    createParentsWithChildren(2);

    $parents = StrictParent::query()->with('children')->get();

    expect($parents->firstOrFail()->children)->toHaveCount(1);
});

it('prevents accessing attributes missing from the selected columns', function (): void {
    createParentsWithChildren(1);

    $child = StrictChild::query()->select('id')->firstOrFail();

    expect(fn (): mixed => $child->strict_parent_id)
        ->toThrow(MissingAttributeException::class);
});

it('prevents silently discarding attributes without a matching column', function (): void {
    expect(fn (): Model => StrictChild::create([
        'strict_parent_id' => StrictParent::create()->id,
        'not_a_column' => 'value',
    ]))->toThrow(MassAssignmentException::class);
});

it('keeps only the data-loss guard armed in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    (new AppServiceProvider(app()))->boot();

    expect(Model::preventsLazyLoading())->toBeFalse()
        ->and(Model::preventsAccessingMissingAttributes())->toBeFalse()
        ->and(Model::preventsSilentlyDiscardingAttributes())->toBeTrue();
})->after(function (): void {
    app()->detectEnvironment(fn (): string => 'testing');

    Model::shouldBeStrict();
    DB::prohibitDestructiveCommands(false);
    Password::defaults(fn (): ?Password => null);
});

/**
 * @property int $id
 */
class StrictParent extends Model
{
    protected $table = 'strict_parents';

    public $timestamps = false;

    protected $guarded = [];

    /** @return HasMany<StrictChild, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(StrictChild::class, 'strict_parent_id');
    }
}

/**
 * @property int $id
 * @property int $strict_parent_id
 */
class StrictChild extends Model
{
    protected $table = 'strict_children';

    public $timestamps = false;

    protected $fillable = ['strict_parent_id'];
}
