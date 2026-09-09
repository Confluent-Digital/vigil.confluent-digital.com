<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Core\Sandbox;
use App\Modules\Sandbox\Fixtures;
use App\Tests\DatabaseTestCase;

/**
 * Le jeu de test doit pouvoir etre cree et supprime sans laisser de trace, et
 * SANS toucher aux donnees reelles. La suppression se fie entierement au
 * prefixe `[BAC A SABLE]` : si quelque chose est cree sans lui, il survit au
 * menage et se melange aux chiffres reels.
 */
final class SandboxFixturesTest extends DatabaseTestCase
{
    /** @return array<string,int> */
    private function totaux(): array
    {
        return [
            'clients'     => $this->countRows('t_client'),
            'publishers'  => $this->countRows('t_publisher'),
            'campagnes'   => $this->countRows('t_campaign'),
            'acces'       => $this->countRows('t_campaign_publisher'),
            'destinations'=> $this->countRows('t_campaign_postback'),
            'clics'       => $this->countRows('t_click'),
            'conversions' => $this->countRows('t_conversion'),
            'relais'      => $this->countRows('t_postback_queue'),
            'stats'       => $this->countRows('t_stats_hourly'),
        ];
    }

    /** Une donnee reelle, qui ne porte PAS le prefixe. */
    private function temoin(): void
    {
        $this->pdo->prepare('INSERT INTO t_client (client_name) VALUES (:n)')
            ->execute(['n' => 'Client Reel Temoin']);
        $this->pdo->prepare('INSERT INTO t_publisher (publisher_name, publisher_token) VALUES (:n, :t)')
            ->execute(['n' => 'Publisher Reel Temoin', 't' => 'reel' . substr(bin2hex(random_bytes(3)), 0, 6)]);
    }

    public function testLeJeuCreeCeQuIlAnnonce(): void
    {
        $c = Fixtures::create();

        self::assertSame(3, $c['clients']);
        self::assertSame(4, $c['publishers']);
        self::assertSame(5, $c['campagnes']);
        self::assertGreaterThan(0, $c['acces']);

        // Une destination par campagne, plus le pixel de trois publishers.
        self::assertSame(5 + 3, $c['destinations']);
    }

    /**
     * La matrice est volontairement incomplete : c'est ce qui rend visible que
     * l'autorisation n'est pas un controle mais l'absence de jeton.
     */
    public function testLaMatriceDAccesEstIncomplete(): void
    {
        $c = Fixtures::create();

        self::assertLessThan(
            $c['campagnes'] * $c['publishers'],
            $c['acces'],
            'tous les publishers ne doivent pas avoir acces a toutes les campagnes'
        );
    }

    public function testTroisPublishersSurQuatreOntUnPixel(): void
    {
        Fixtures::create();

        self::assertSame(
            3,
            $this->countRows(
                't_campaign_postback',
                'cpb_id_campaign IS NULL AND cpb_id_publisher IS NOT NULL'
            ),
            'un publisher sans pixel doit exister, pour voir la difference'
        );
    }

    public function testLeTraficRemplitLesTables(): void
    {
        Fixtures::create();
        $r = Fixtures::traffic(60);

        self::assertSame(60, $r['clics']);
        self::assertSame(60, $this->countRows('t_click'));
        self::assertSame($r['conversions'], $this->countRows('t_conversion'));
        self::assertGreaterThan(0, $r['conversions'], 'le tirage doit produire des conversions');
    }

    /**
     * Le test central : apres suppression, il ne doit rien rester du jeu —
     * ni clic, ni conversion, ni relai, ni ligne de statistiques — et les
     * donnees reelles doivent etre intactes.
     */
    public function testLaSuppressionNeLaisseRienEtEpargneLeReel(): void
    {
        $this->temoin();
        $avant = $this->totaux();

        Fixtures::create();
        Fixtures::traffic(40);

        $pendant = $this->totaux();
        self::assertGreaterThan($avant['clics'], $pendant['clics']);
        self::assertGreaterThan($avant['campagnes'], $pendant['campagnes']);

        Fixtures::destroy();

        // Le trafic laisse des lignes de statistiques : elles sont rattachees
        // aux campagnes supprimees, donc elles partent avec.
        $apres = $this->totaux();
        foreach ($avant as $quoi => $n) {
            self::assertSame($n, $apres[$quoi], "« $quoi » doit revenir a son etat d'avant");
        }

        self::assertSame(1, $this->countRows('t_client', 'client_name = :n', ['n' => 'Client Reel Temoin']));
        self::assertSame(1, $this->countRows('t_publisher', 'publisher_name = :n', ['n' => 'Publisher Reel Temoin']));
    }

    /**
     * Deux publics, deux adresses — et elles ne sont PAS interchangeables :
     *
     *   `vigil_nginx`   joignable depuis les conteneurs, pas du navigateur
     *   `APP_URL`       joignable du navigateur, pas depuis les conteneurs
     *
     * La destination d'une campagne est suivie par le VISITEUR : elle doit
     * porter l'adresse publique. Les relais sont livres par le WORKER : ils
     * doivent porter l'adresse interne. Les confondre casse silencieusement
     * l'un des deux cotes — la redirection menait le navigateur vers
     * `http://vigil_nginx/...`, qui ne resout pas.
     */
    public function testLaDestinationEstPubliqueEtLesRelaisInternes(): void
    {
        Fixtures::create();

        $publique = \App\Core\Sandbox::publicBaseUrl();
        $interne  = \App\Core\Sandbox::internalBaseUrl();

        $destinations = $this->pdo->query(
            'SELECT campaign_dest_url FROM t_campaign'
        )->fetchAll(\PDO::FETCH_COLUMN);

        self::assertNotEmpty($destinations);
        foreach ($destinations as $url) {
            self::assertStringStartsWith(
                $publique,
                (string) $url,
                'la destination est suivie par le navigateur : adresse publique obligatoire'
            );
        }

        $relais = $this->pdo->query('SELECT cpb_url FROM t_campaign_postback')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertNotEmpty($relais);
        foreach ($relais as $url) {
            self::assertStringStartsWith(
                $interne,
                (string) $url,
                'un relai est livre par le worker depuis le conteneur : adresse interne'
            );
        }
    }

    public function testToutCeQuiEstCreePorteLePrefixe(): void
    {
        Fixtures::create();

        $motif = Sandbox::PREFIX . '%';

        self::assertSame(
            0,
            $this->countRows('t_client', 'client_name NOT LIKE :m', ['m' => $motif]),
            'aucun client sans prefixe ne doit avoir ete cree'
        );
        self::assertSame(
            0,
            $this->countRows('t_publisher', 'publisher_name NOT LIKE :m', ['m' => $motif]),
            'aucun publisher sans prefixe ne doit avoir ete cree'
        );
        self::assertSame(
            0,
            $this->countRows('t_campaign', 'campaign_name NOT LIKE :m', ['m' => $motif]),
            'aucune campagne sans prefixe ne doit avoir ete creee'
        );
    }
}
