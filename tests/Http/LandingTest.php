<?php

declare(strict_types=1);

namespace App\Tests\Http;

/**
 * La fausse page d'atterrissage `/lp`.
 *
 * Ce qui doit rester vrai, et qui est la seule raison pour laquelle elle a le
 * droit d'exister en production : **elle ne declenche rien**. Le bac a sable en
 * est exclu parce que son faux money site appelle `/pb` avec le secret lu en
 * base — un outil de forge de conversions. Celle-ci se contente de refleter.
 */
final class LandingTest extends HttpTestCase
{
    public function testElleRefleteLIdentifiantEtLePreRemplissage(): void
    {
        $clic = \App\Core\Ulid::generate();

        $r = $this->get('/lp?clickid=' . $clic . '&prenom=Marie&email=marie@exemple.fr&s1=news');

        self::assertSame(200, $r['status']);
        self::assertStringContainsString($clic, $r['body'], "l'identifiant recu doit etre affiche");
        self::assertStringContainsString('Marie', $r['body'], 'le pre-remplissage doit etre visible');
        self::assertStringContainsString('news', $r['body'], 'les autres parametres aussi');
    }

    /** Une page de test portant un identifiant de clic n'a rien a faire dans un index. */
    public function testElleRefuseLIndexationEtLeReferer(): void
    {
        $r = $this->get('/lp?clickid=' . \App\Core\Ulid::generate());

        self::assertStringContainsString('noindex', $r['headers']['x-robots-tag'] ?? '');
        self::assertSame('no-referrer', $r['headers']['referrer-policy'] ?? '');
        self::assertStringContainsString('no-store', $r['headers']['cache-control'] ?? '');
    }

    /**
     * L'INVARIANT. Le corps ne doit contenir aucun secret, et la page ne doit
     * pas proposer de declencheur : l'URL de postback qu'elle prepare porte un
     * emplacement, jamais une valeur.
     */
    public function testElleNeDivulgueAucunSecretEtNeDeclencheRien(): void
    {
        $ctx  = $this->makeCampaign('https://money.test/?c={clickid}');
        $clic = \App\Core\Ulid::generate();

        $avant = $this->countRows('t_conversion', 'conversion_id_campaign = :c', ['c' => $ctx['campaign_id']]);

        $r = $this->get('/lp?clickid=' . $clic . '&s=' . $ctx['secret']);

        self::assertStringNotContainsString(
            $ctx['secret'],
            $r['body'],
            'le secret ne doit jamais apparaitre, meme renvoye par l\'appelant'
        );
        self::assertStringContainsString('VOTRE_SECRET', $r['body'], 'un emplacement, pas une valeur');
        self::assertStringNotContainsString('<form', $r['body'], 'aucun formulaire de declenchement');

        self::assertSame(
            $avant,
            $this->countRows('t_conversion', 'conversion_id_campaign = :c', ['c' => $ctx['campaign_id']]),
            'ouvrir la page ne cree aucune conversion'
        );
    }

    /**
     * Le cas d'erreur le plus probable au premier branchement : la destination
     * porte `{external_clickid}` au lieu de `{clickid}`, et le money site
     * recoit l'identifiant de la plateforme externe. La page doit le nommer.
     */
    public function testElleSignaleUnIdentifiantQuiNEstPasLeNotre(): void
    {
        $r = $this->get('/lp?clickid=CD-KSGJY92K');

        self::assertSame(200, $r['status']);
        self::assertStringContainsString('26 caractères', $r['body']);
    }

    public function testSansIdentifiantElleNommeLaMacroManquante(): void
    {
        $r = $this->get('/lp');

        self::assertSame(200, $r['status']);
        self::assertStringContainsString('{clickid}', $r['body']);
    }
}
