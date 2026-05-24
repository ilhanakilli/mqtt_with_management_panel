Markdown
# MQTT Broker ve Gelişmiş Trafik Yönetim Paneli

Docker tabanlı, kullanıcı bazlı canlı trafik takibi, otomatik kota/ceza (ban) sistemi ve web yönetim arayüzü barındıran gelişmiş Mosquitto MQTT Broker altyapısı.

## 1. Sistem Gereksinimleri ve Kurulum (Raspberry Pi & Linux)

Ubuntu/Debian tabanlı Linux dağıtımları ve Raspberry Pi üzerinde altyapıyı hazırlamak için aşağıdaki komutları sırasıyla çalıştırın:

**Git ve Docker Kurulumu:**
```bash
sudo apt update && sudo apt install git -y
curl -fsSL [https://get.docker.com](https://get.docker.com) -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER
newgrp docker
sudo apt install docker-compose-plugin -y
Raspberry Pi Özel Notu: Cihazda dahili saat pili (RTC) bulunmadığından, sistem kapalı kaldığında Docker kurulumu sırasında depo güvenlik imzaları (GPG) zaman uyuşmazlığı hatası verebilir. Not live until... hatası alırsanız saati senkronize etmek için sudo systemctl restart systemd-timesyncd komutunu çalıştırıp Docker kurulum betiğini tekrar başlatın.

2. Projenin İndirilmesi ve Hazırlık
Depoyu sunucuya klonlayın ve proje dizinine girin:

Bash
git clone [https://github.com/ilhanakilli/mqtt_with_management_panel.git](https://github.com/ilhanakilli/mqtt_with_management_panel.git)
cd mqtt_with_management_panel
Gerekli klasörleri, log ve boş veritabanı dosyalarını oluşturup izinlerini ayarlayın (Güvenlik gereği .gitignore ile yoksayıldıkları için ilk kurulumda manuel oluşturulmalıdır):

Bash
mkdir -p mosquitto/log mosquitto/data
touch mosquitto/config/users.txt
sudo chmod 664 mosquitto/config/users.txt
touch mosquitto/config/traffic_db.json
sudo chmod 666 mosquitto/config/traffic_db.json
3. Panel Şifresini Belirleme
Yönetim paneline yetkisiz erişimi engellemek için kendi şifrenizi tanımlayın:

Bash
nano panel-src/index.php
Dosyanın üst kısmındaki $env_pass = 'PASSWORD'; satırını bulun. 'PASSWORD' alanını silip kendi güçlü şifrenizi yazın, ardından dosyayı kaydedip çıkın.

4. Port Yapılandırması
Çakışmaları önlemek amacıyla standart MQTT portları değiştirilmiştir. Varsayılan yapılandırma:

MQTT TCP Portu: 2883 (IoT cihazlarının bağlanacağı port)

MQTT WebSockets Portu: 2884 (Web/Arayüz istemcilerinin bağlanacağı port)

Web Yönetim Paneli Portu: 8880 (Arayüze erişim portu)

Port Değişikliği:
Port numaralarını değiştirmek isterseniz docker-compose.yml dosyasındaki sol taraf (host) portlarını ve mosquitto/config/mosquitto.conf dosyasındaki listener tanımlamalarını senkronize şekilde düzenlemeniz gerekir.

5. Sistemi Başlatma
Tüm hazırlıklar tamamlandıktan sonra servisleri arka planda çalıştırın:

Bash
docker compose up -d
Sistem ayağa kalktığında tarayıcınızdan http://SUNUCU_IP_ADRESI:8880 adresine giderek yönetim paneline erişebilir, kullanıcı ekleme ve limit işlemlerini yapabilirsiniz.
