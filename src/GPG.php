<?php

namespace Certly\GPG;

class GPG
{
    /**
     * @var int
     */
    private $width = 16;

    /**
     * @var array
     */
    private $keySizes = [2049, 4097];

    /**
     * @var string
     */
    private $version = '1.6.1';

    /**
     * @param $key
     * @param $text
     *
     * @return string
     */
    private function gpgEncrypt($key, $text)
    {
        $len = safeStrlen($text);
        $iblock = array_fill(0, $this->width, 0);
        $rblock = array_fill(0, $this->width, 0);

        $cipher = '';

        if ($len % $this->width) {
            for ($i = ($len % $this->width); $i < $this->width; $i++) {
                $text .= "\0";
            }
        }

        $ekey = new Expanded_Key($key);

        for ($i = 0; $i < $this->width; $i++) {
            $iblock[$i] = 0;
            $rblock[$i] = Utility::c_random();
        }

        for ($n = 0; $n < safeStrlen($text); $n += $this->width) {
            $iblock = AES::encrypt($iblock, $ekey);
            for ($i = 0; $i < $this->width; $i++) {
                $iblock[$i] ^= ord($text[$n + $i]);
                $cipher .= chr($iblock[$i]);
            }
        }

        return substr($cipher, 0, $len);
    }

    /**
     * @param $tag
     * @param $len
     *
     * @return string
     */
    private function gpgHeader($tag, $len)
    {
        $h = '';
        if ($len < 0x100) {
            $h .= chr($tag);
            $h .= chr($len);
        } elseif ($len < 0x10000) {
            $tag += 1;
            $h .= chr($tag);
            $h .= $this->writeNumber($len, 2);
        } else {
            $tag += 2;
            $h .= chr($tag);
            $h .= $this->writeNumber($len, 4);
        }

        return $h;
    }

    private function writeNumber($n, $bytes)
    {
        // credits for this function go to OpenPGP.js
        $b = '';
        for ($i = 0; $i < $bytes; $i++) {
            $b .= chr(($n >> (8 * ($bytes - $i - 1))) & 0xff);
        }

        return $b;
    }

    private function gpgSession($key_id, $key_type, $session_key, $public_key)
    {
        $mod = [];
        $exp = [];
        $enc = '';

        $s = base64_decode($public_key);
        $l = floor((ord($s[0]) * 256 + ord($s[1]) + 7) / 8);
        $mod = mpi2b(substr($s, 0, $l + 2));
        if ($key_type) {
            $grp = [];
            $y = [];
            $B = [];
            $C = [];

            $l2 = floor((ord($s[$l + 2]) * 256 + ord($s[$l + 3]) + 7) / 8) + 2;
            $grp = mpi2b(substr($s, $l + 2, $l2));
            $y = mpi2b(substr($s, $l + 2 + $l2));
            $exp[0] = $this->keySizes[Utility::c_random() & 7];
            $B = bmodexp($grp, $exp, $mod);
            $C = bmodexp($y, $exp, $mod);
        } else {
            $exp = mpi2b(substr($s, $l + 2));
        }

        $c = 0;
        $lsk = strlen($session_key);
        for ($i = 0; $i < $lsk; $i++) {
            $c += ord($session_key[$i]);
        }
        $c &= 0xffff;

        $lm = ($l - 2) * 8 + 2;
        $m = chr($lm / 256).chr($lm % 256).
            chr(2).Utility::s_random($l - $lsk - 6, 1)."\0".
            chr(7).$session_key.
            chr($c / 256).chr($c & 0xff);

        if ($key_type) {
            $enc = b2mpi($B).b2mpi(bmod(bmul(mpi2b($m), $C), $mod));

            return $this->gpgHeader(0x84, strlen($enc) + 10).
                chr(3).$key_id.chr(16).$enc;
        } else {
            $enc = b2mpi(bmodexp(mpi2b($m), $exp, $mod));

            return $this->gpgHeader(0x84, strlen($enc) + 10).
                chr(3).$key_id.chr(1).$enc;
        }
    }

    private function gpgLiteral($text)
    {
        if (strpos($text, "\r\n") === false) {
            $text = str_replace("\n", "\r\n", $text);
        }

        return chr(11 | 0xC0).chr(255).$this->writeNumber(safeStrlen($text) + 10, 4).'t'.chr(4)."file\0\0\0\0".$text;
    }

    private function gpgData($key, $text)
    {
        $prefix = Utility::s_random($this->width, 0);
        $prefix .= substr($prefix, -2);
        $mdc = "\xD3\x14".hash('sha1', $prefix.$this->gpgLiteral($text)."\xD3\x14", true);
        $enc = $this->gpgEncrypt($key, $prefix.$this->gpgLiteral($text).$mdc);

        return chr(0x12 | 0xC0).chr(255).$this->writeNumber(1 + strlen($enc), 4).chr(1).$enc;
    }

    /**
     * Encrypts a message with the provided public key.
     *
     * @param Public_Key $pk
     * @param string     $plaintext
     * @param string     $versionHeader
     *
     * @return string encrypted text
     */
    public function encrypt($pk, $plaintext, $versionHeader = null)
    {
        // normalize the public key
        $key_id = $pk->GetKeyId();
        $key_type = $pk->GetKeyType();
        $public_key = $pk->GetPublicKey();

        $session_key = Utility::s_random($this->width, 0);
        $key_id = Utility::hex2bin($key_id);
        $cp = $this->gpgSession($key_id, $key_type, $session_key, $public_key).
        $this->gpgData($session_key, $plaintext);

        $code = base64_encode($cp);
        $code = wordwrap($code, 64, "\n", 1);

        if ($versionHeader === null) {
            $versionHeader = 'Version: VerySimple PHP-GPG v'.$this->version."\n\n";
        } elseif (safeStrlen($versionHeader) > 0) {
            $versionHeader = 'Version: '.$versionHeader."\n\n";
        }

        return
            "-----BEGIN PGP MESSAGE-----\n".
            $versionHeader.
            $code."\n=".base64_encode(Utility::crc24($cp)).
            "\n-----END PGP MESSAGE-----\n";
    }
}
