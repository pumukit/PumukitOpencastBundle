<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Tests\Api;

use PHPUnit\Framework\TestCase;
use Pumukit\OpencastBundle\Api\Opencast16Api;
use Pumukit\OpencastBundle\Api\OpencastApiResolver;
use Pumukit\OpencastBundle\Api\OpencastUser;
use Pumukit\OpencastBundle\Exception\OpencastNotFoundException;
use Pumukit\OpencastBundle\Exception\UnsupportedOpencastVersionException;
use Pumukit\OpencastBundle\Tests\FakeOpencastHttpClient;

/**
 * @internal
 *
 * @coversNothing
 */
class Opencast16ApiTest extends TestCase
{
    public function testItSupportsOpencast16AndLater(): void
    {
        $api = new Opencast16Api();

        $this->assertTrue($api->supports('16.12.0'));
        $this->assertTrue($api->supports('18.1'));
        $this->assertFalse($api->supports('15.9.0'));
    }

    public function testTheResolverPicksTheApiOfTheServerVersion(): void
    {
        $http = (new FakeOpencastHttpClient())->respond('GET', '/info/health', 200, '{"releaseId":"16.12.0"}');

        $this->assertInstanceOf(Opencast16Api::class, (new OpencastApiResolver([new Opencast16Api()]))->getApi($http));
    }

    public function testAnOlderServerNeedsAnotherApi(): void
    {
        $http = (new FakeOpencastHttpClient())
            ->respond('GET', '/info/health', 404)
            ->respond('GET', '/info/components.json', 200, '{"rest":[{"version":"12.4"}]}')
        ;

        $this->expectException(UnsupportedOpencastVersionException::class);

        (new OpencastApiResolver([new Opencast16Api()]))->getApi($http);
    }

    public function testTheSearchReturnsTheMediaPackagesAndTheTotal(): void
    {
        $http = (new FakeOpencastHttpClient())->respond('GET', '/search/episode.json*', 200, (string) file_get_contents(__DIR__.'/../Fixtures/search.json'));

        $result = (new Opencast16Api())->searchMediaPackages($http, 'clase', 10, 20);

        $this->assertSame(1, $result->total);
        $this->assertSame(['f50344d2-b74d-445a-9144-e1cc6352fef0'], $result->ids());
        $this->assertSame('2026-11-01', $result->mediaPackages[0]->modified()->format('Y-m-d'));
        $this->assertSame('/search/episode.json?q=clase&limit=10&offset=20&sort=modified+desc', $http->requests[0][1]);
    }

    public function testAMissingArchivedMediaPackageIsNull(): void
    {
        $this->assertNull((new Opencast16Api())->getArchivedMediaPackage(new FakeOpencastHttpClient(), 'missing'));
    }

    public function testDeletingAMissingEventIsReported(): void
    {
        $this->expectException(OpencastNotFoundException::class);

        (new Opencast16Api())->deleteEvent(new FakeOpencastHttpClient(), 'missing');
    }

    public function testTheSeriesIsCreatedWithItsMetadataAndReturnsItsId(): void
    {
        $http = (new FakeOpencastHttpClient())->respond('POST', '/api/series', 201, '{"identifier":"new-series"}');

        $this->assertSame('new-series', (new Opencast16Api())->createSeries($http, 'Matemáticas', 'Álgebra'));
        $metadata = json_decode($http->requests[0][2]['metadata'], true);
        $this->assertSame('Matemáticas', $metadata[0]['fields'][0]['value']);
        $this->assertTrue($http->requests[0][3]);
    }

    public function testTheUsersGetARandomPassword(): void
    {
        $http = (new FakeOpencastHttpClient())->respond('POST', '/user-utils/', 201)->respond('PUT', '/user-utils/jdoe.json', 200);
        $api = new Opencast16Api();
        $user = new OpencastUser('jdoe', 'John Doe', 'jdoe@example.org', ['ROLE_USER']);

        $api->createUser($http, $user);
        $api->updateUser($http, $user);

        $this->assertNotSame('pumukit', $http->requests[0][2]['password']);
        $this->assertNotSame($http->requests[0][2]['password'], $http->requests[1][2]['password']);
        $this->assertSame('["ROLE_USER"]', $http->requests[0][2]['roles']);
        $this->assertSame('John Doe', $http->requests[0][2]['name']);
    }

    public function testTheMediaPackagesOfASucceededWorkflowAreFound(): void
    {
        $http = (new FakeOpencastHttpClient())
            ->respond('GET', '/api/events*', 200, '[{"identifier":"a","publication_status":["internal"]},{"identifier":"b"},{"identifier":"c","publication_status":["engage-player"]}]')
            ->respond('GET', '/workflow/mediaPackage/a/instances.json', 200, '{"workflows":{"workflow":[{"template":"retract","state":"SUCCEEDED"}]}}')
            ->respond('GET', '/workflow/mediaPackage/b/instances.json', 200, '{"workflows":{"workflow":{"template":"retract","state":"FAILED"}}}')
        ;

        $this->assertSame(['a'], (new Opencast16Api())->getSucceededWorkflowMediaPackageIds($http, 'retract'));
        $this->assertNotContains('/workflow/mediaPackage/c/instances.json', array_column($http->requests, 1));
    }
}
