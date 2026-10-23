<?php

namespace ExclusiveBBB\Tests;

use ExclusiveBBB\BBB\Client;
use ExclusiveBBB\BBB\ClientException;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    /**
     * @dataProvider urls
     */
    public function testNormalizeUrl(string $input): void
    {
        $this->assertSame('https://bbb.example.org/bigbluebutton/api/', Client::normalizeUrl($input));
    }

    public static function urls(): array
    {
        return [
            ['https://bbb.example.org'],
            ['https://bbb.example.org/'],
            ['https://bbb.example.org/bigbluebutton'],
            ['https://bbb.example.org/bigbluebutton/'],
            ['https://bbb.example.org/bigbluebutton/api'],
        ];
    }

    public function testChecksumFollowsBbbSpec(): void
    {
        // example from the BBB API documentation (sha1)
        $client = new Client('https://bbb.example.org', '639259d4-9dd8-4b25-bf01-95f9567eaf4b', 'sha1');
        $query = 'name=Test+Meeting&meetingID=abc123&attendeePW=111222&moderatorPW=333444';
        $this->assertSame('1fcbb0c4fc1f039f73aa6d697d2db9ba7f803f17', $client->checksum('create', $query));
    }

    public function testJoinUrlUsesRoleAndIsSigned(): void
    {
        $client = new Client('https://bbb.example.org', 'secret', 'sha256');
        $url = $client->joinUrl('m-1', 'Erika Mustermann', true);

        $this->assertStringStartsWith('https://bbb.example.org/bigbluebutton/api/join?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame('MODERATOR', $params['role']);
        $this->assertSame('Erika Mustermann', $params['fullName']);
        $this->assertArrayNotHasKey('password', $params);

        $query = substr(parse_url($url, PHP_URL_QUERY), 0, strpos(parse_url($url, PHP_URL_QUERY), '&checksum='));
        $this->assertSame(hash('sha256', 'join' . $query . 'secret'), $params['checksum']);
        $this->assertStringNotContainsString('secret', $url);
    }

    public function testGetMeetingsParsesMetadata(): void
    {
        $xml = <<<XML
<response>
  <returncode>SUCCESS</returncode>
  <meetings>
    <meeting>
      <meetingName>Senat</meetingName>
      <meetingID>abc-20261102-0945</meetingID>
      <createTime>1793605500000</createTime>
      <running>true</running>
      <participantCount>42</participantCount>
      <moderatorCount>2</moderatorCount>
      <videoCount>3</videoCount>
      <metadata>
        <exbbb-conference>c0ffee</exbbb-conference>
      </metadata>
    </meeting>
  </meetings>
</response>
XML;
        $client = new Client('https://bbb.example.org', 'secret', 'sha256', 10, fn() => $xml);
        $meetings = $client->getMeetings();

        $this->assertCount(1, $meetings);
        $this->assertSame('abc-20261102-0945', $meetings[0]['meetingID']);
        $this->assertSame(42, $meetings[0]['participantCount']);
        $this->assertTrue($meetings[0]['running']);
        $this->assertSame('c0ffee', $meetings[0]['metadata']['exbbb-conference']);
    }

    public function testGetMeetingsWithoutMeetings(): void
    {
        $xml = '<response><returncode>SUCCESS</returncode><meetings/><messageKey>noMeetings</messageKey></response>';
        $client = new Client('https://bbb.example.org', 'secret', 'sha256', 10, fn() => $xml);
        $this->assertSame([], $client->getMeetings());
    }

    public function testFailedResponseThrows(): void
    {
        $xml = '<response><returncode>FAILED</returncode><messageKey>checksumError</messageKey><message>bad checksum</message></response>';
        $client = new Client('https://bbb.example.org', 'secret', 'sha256', 10, fn() => $xml);

        try {
            $client->create(['meetingID' => 'x']);
            $this->fail('expected exception');
        } catch (ClientException $e) {
            $this->assertSame('checksumError', $e->messageKey);
        }
    }

    public function testEndIgnoresNotFound(): void
    {
        $xml = '<response><returncode>FAILED</returncode><messageKey>notFound</messageKey><message>gone</message></response>';
        $client = new Client('https://bbb.example.org', 'secret', 'sha256', 10, fn() => $xml);
        $client->end('x');
        $this->addToAssertionCount(1);
    }
}
