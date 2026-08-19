<?php

declare(strict_types=1);

namespace Tbank\Invest;

use Tbank\Invest\Exception\TInvestException;

final class InstrumentIdKind
{
    public const string UID = 'INSTRUMENT_ID_TYPE_UID';
    public const string FIGI = 'INSTRUMENT_ID_TYPE_FIGI';
    public const string TICKER = 'INSTRUMENT_ID_TYPE_TICKER';
    public const string POSITION_UID = 'INSTRUMENT_ID_TYPE_POSITION_UID';
    public const string QUERY = 'QUERY';
}

final class InstrumentRef
{
    public function __construct(
        public readonly string $raw,
        public readonly string $kind,
        public readonly string $instrumentId,
        public readonly ?string $ticker = null,
        public readonly ?string $classCode = null,
        public readonly ?string $idType = null,
    ) {
    }
}

final class InstrumentId
{
    private const string UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const string FIGI_RE = '/^(BBG|TCS)[A-Z0-9]{9,}$/i';
    private const string ISIN_RE = '/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/i';

    public static function classify(string $value, ?string $classCode = null): InstrumentRef
    {
        $raw = trim($value);
        if ($raw === '') {
            throw new TInvestException('instrument id must not be empty', 400, null, null, 'invalid_instrument');
        }

        if (str_contains($raw, '_') && !preg_match(self::UUID_RE, $raw)) {
            $pos = strrpos($raw, '_');
            $ticker = substr($raw, 0, $pos);
            $maybeClass = substr($raw, $pos + 1);
            if ($ticker !== '' && $maybeClass !== '' && strtoupper($maybeClass) === $maybeClass && strlen($maybeClass) <= 12) {
                return new InstrumentRef(
                    $raw,
                    InstrumentIdKind::TICKER,
                    $raw,
                    $ticker,
                    $maybeClass,
                    InstrumentIdKind::TICKER,
                );
            }
        }

        if (preg_match(self::UUID_RE, $raw)) {
            return new InstrumentRef($raw, InstrumentIdKind::UID, $raw, null, $classCode, InstrumentIdKind::UID);
        }
        if (preg_match(self::FIGI_RE, $raw)) {
            return new InstrumentRef($raw, InstrumentIdKind::FIGI, $raw, null, $classCode, InstrumentIdKind::FIGI);
        }
        if (preg_match(self::ISIN_RE, $raw)) {
            return new InstrumentRef($raw, InstrumentIdKind::QUERY, $raw, null, $classCode);
        }
        if ($classCode) {
            return new InstrumentRef(
                $raw,
                InstrumentIdKind::TICKER,
                "{$raw}_{$classCode}",
                $raw,
                $classCode,
                InstrumentIdKind::TICKER,
            );
        }
        if (strtoupper($raw) === $raw && strlen($raw) >= 1 && strlen($raw) <= 12 && preg_match('/^[A-Z0-9.\-]+$/', $raw)) {
            return new InstrumentRef($raw, InstrumentIdKind::TICKER, $raw, $raw, null, InstrumentIdKind::TICKER);
        }

        return new InstrumentRef($raw, InstrumentIdKind::QUERY, $raw);
    }

    /** @return array<string, string> */
    public static function byRequest(InstrumentRef $ref): array
    {
        return match ($ref->kind) {
            InstrumentIdKind::TICKER => array_filter([
                'idType' => InstrumentIdKind::TICKER,
                'id' => $ref->ticker ?? $ref->raw,
                'classCode' => $ref->classCode,
            ], static fn ($v) => $v !== null && $v !== ''),
            InstrumentIdKind::FIGI => ['idType' => InstrumentIdKind::FIGI, 'id' => $ref->instrumentId],
            InstrumentIdKind::UID => ['idType' => InstrumentIdKind::UID, 'id' => $ref->instrumentId],
            InstrumentIdKind::POSITION_UID => ['idType' => InstrumentIdKind::POSITION_UID, 'id' => $ref->instrumentId],
            default => ['idType' => InstrumentIdKind::FIGI, 'id' => $ref->raw],
        };
    }
}
