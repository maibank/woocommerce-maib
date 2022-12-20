WooCommerce Payment Gateway - maib Moldova
---------------------------------------------

[![N|Solid](https://www.maib.md/images/logo.svg)](https://www.maib.md)

https://wordpress.org/plugins/wc-moldovaagroindbank/

-------------


[RO] Ghidul de testare și certificatul de testare (0149583.pfx) le găsiți în acest repozitoriu.

[RU] Руководство по тестированию и тестовый сертификат (0149583.pfx) можете найти в этом репозитории.

[EN] The testing guide and test certificate (0149583.pfx) you can find in this repository.

Advanced settings
----------------
For test mode use *.pem* certificate from this repository (test-certificate.pem/test-key.pem).

For live mode use openssl to extract *.pem* keys from *.pfx* file and password provided by **maib**:
        
        # Public key chain:
          openssl pkcs12 -in certname.pfx -nokeys -out cert.pem
        # The private key with password:
          openssl pkcs12 -in certname.pfx -nocerts -out key.pem
