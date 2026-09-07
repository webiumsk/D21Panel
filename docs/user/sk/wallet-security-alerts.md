---
title: "Bezpečnostné upozornenia a sledovanie zmien peňaženky"
category: wallet-connection
order: 8
meta_description: "Ako Satflux chráni vašu prijímaciu peňaženku: potvrdenie zmien e-mailovým kódom, pripnuté bezpečnostné správy a automatická detekcia zmien konfigurácie mimo Satfluxu."
updated: 2026-09-06
---

Prijímacia peňaženka rozhoduje o tom, kam skončí každá platba. Satflux preto berie každú jej zmenu ako bezpečnostnú udalosť.

## Zmeny vyžadujú e-mailový kód

Nahradenie **pripojenej** peňaženky (nový connection string, nová Lightning adresa, prechod na Cashu alebo nové párovanie so SamRock) si najprv vypýta 6-miestny kód poslaný na e-mail vášho účtu. Kód platí 15 minút a na jednu zmenu. Hosťovské účty nemajú schránku, preto sú najprv vyzvané na prechod na bezplatný účet. Pozri [Odvolanie alebo zmena údajov peňaženky](/documentation/revoking-or-changing-wallet-credentials).

## Bezpečnostné správy

Niektoré udalosti vytvoria **bezpečnostnú správu** v Správach, pripnutú nad bežné platobné notifikácie s červeným štítkom "Bezpečnosť". Kým máte neprečítané bezpečnostné správy, na každej stránke sa pod hlavičkou zobrazuje červený pruh. Ide o tieto udalosti:

- **Pripojenie peňaženky nahradené** - prijímacia peňaženka obchodu bola zmenená (posiela sa aj e-mailom).
- **Secret peňaženky odhalený** - uložený secret pripojenia bol zobrazený, buď vami (po e-mailovom kóde), alebo podporou Satfluxu.
- **Konfigurácia peňaženky zmenená mimo Satfluxu** - pozri nižšie (posiela sa aj e-mailom).
- **Konfigurácia peňaženky obnovená** - konfigurácia opäť sedí s vašou peňaženkou.

Ak dostanete bezpečnostnú správu o niečom, čo ste neurobili, okamžite znova pripojte svoju peňaženku a kontaktujte podporu.

## Automatická detekcia zmien mimo Satfluxu

Pri pripojení peňaženky si Satflux uloží odtlačok platobnej konfigurácie na platobnom serveri. Každých 10 minút a vždy, keď má faktúra vášho obchodu aktivitu, Satflux znova načíta živú konfiguráciu a porovná ju s odtlačkom. Obchodníci nemajú priamy prístup na platobný server, takže akýkoľvek rozdiel znamená, že konfiguráciu zmenil niekto iný.

Keď sa zistí rozdiel:

- na stránke **Pripojenie peňaženky** obchodu sa zobrazí červené varovanie s dotknutými platobnými metódami,
- dostanete bezpečnostnú správu a e-mail,
- administrátori Satfluxu sú upozornení a incident vidia v logu zmien peňaženiek.

**Čo urobiť:** otvorte stránku Pripojenie peňaženky, potvrďte zmenu e-mailovým kódom a znova pripojte svoju peňaženku. Nové pripojenie uloží nový odtlačok a incident uzavrie. Potom kontaktujte podporu, aby sme prešetrili, ako bola konfigurácia zmenená.

## Kto dostáva peniaze - overenie príjemcu

Každú Lightning faktúru podpisuje uzol, ktorý platbu prijíma. Pri pripojení peňaženky si Satflux od platobného servera vypýta malú testovaciu faktúru (nikdy sa neplatí a hneď sa archivuje), prečíta z nej podpisujúci uzol a zapamätá si ho ako uzol vašej peňaženky. Ak sa testovacia faktúra nedá prečítať, zapamätá si uzol z prvej zaplatenej faktúry.

Odvtedy sa kontroluje každá nová Lightning platba: uzol, ktorý podpísal jej faktúru, musí byť jedným z uzlov vašej peňaženky. Platby prijaté pred pripojením peňaženky alebo pred naučením uzla sú história a neposudzujú sa. Poskytovatelia peňaženiek často prevádzkujú viac uzlov, preto pri platbe z neznámeho uzla si Satflux najprv vypýta od platobného servera čerstvé testovacie faktúry; ak vaša peňaženka práve teraz podpisuje týmto uzlom, uzol sa pridá k uzlom vašej peňaženky a nič sa nehlási. Až uzol, ktorým vaša peňaženka nepodpisuje, vyvolá bezpečnostnú správu a e-mail s číslom faktúry a uzlom a na stránke Pripojenie peňaženky sa zobrazí červené varovanie. Táto kontrola nezávisí od toho, čo platobný server tvrdí o svojej konfigurácii - pozerá sa na faktúry, ktoré boli naozaj zaplatené. Ak sa faktúra nedá dekódovať alebo skontrolovať, zlyhanie sa zaloguje a platba sa aj tak zaznamená; také platby nie sú overené.

Ak ste peňaženku úmyselne presunuli k inému poskytovateľovi, nové pripojenie cez Satflux zapíše nový uzol. Prijať uzol alebo ho znova naučiť môže po prešetrení incidentu s vami len administrátor Satfluxu.

## Čo Satflux nevidí

Detekcia porovnáva, čo platobný server hlási, s tým, čo ste pripojili. Pokrýva zmeny urobené cez rozhranie alebo API platobného servera vrátane ukradnutého API kľúča. Úplne kompromitovaný platobný server by mohol o svojej konfigurácii klamať; preto sa overenie príjemcu vyššie pozerá priamo na zaplatené faktúry. Server, ktorý by sfalšoval aj údaje o faktúrach, by ukazoval platby, ktoré k vám nikdy nedošli, preto sledujte zostatok svojej peňaženky oproti platbám zobrazeným v Satfluxe a ak nesedia, kontaktujte podporu.
