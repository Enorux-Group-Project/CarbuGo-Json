import requests
from bs4 import BeautifulSoup
import json
import time
from html import unescape
from urllib.parse import quote

FUELS = {
    "95": "E10",
    "98": "SP98",
    "diesel": "GO",
}

# city_display = pour toi / logs
# city_url = nom à utiliser dans l'URL carbu
# cp = code postal
# location_id = récupéré depuis carbu
LOCATIONS = [
    # Bruxelles
    ("Bruxelles", "Bruxelles", "1000", "BE_bx_1"),

    # Wallonie
    ("Namur", "Namur", "5000", "BE_nm_1204"),
    ("Liège", "Liège", "4000", "BE_lg_826"),
    ("Charleroi", "Charleroi", "6000", "BE_ht_1578"),
    ("Mons", "Mons", "7000", "BE_ht_1945"),
    ("Tournai", "Tournai", "7500", "BE_ht_2098"),
    ("Arlon", "Arlon", "6700", "BE_lu_1745"),
    ("Verviers", "Verviers", "4800", "BE_lg_1140"),
    ("Wavre", "Wavre", "1300", "BE_bw_81"),
    ("Nivelles", "Nivelles", "1400", "BE_bw_153"),
    ("Ottignies-Louvain-la-Neuve", "Ottignies-Louvain-la-Neuve", "1340", "BE_bw_102"),
    ("Gembloux", "Gembloux", "5030", "BE_nm_1222"),
    ("Dinant", "Dinant", "5500", "BE_nm_1366"),
    ("Marche-en-Famenne", "Marche-en-Famenne", "6900", "BE_lu_1880"),
    ("Bastogne", "Bastogne", "6600", "BE_lu_1705"),
    ("La Louvière", "La Louviere", "7100", "BE_ht_2008"),  
    ("Huy", "Huy", "4500", "BE_lg_1003"),                  
    ("Ciney", "Ciney", "5590","BE_nm_1479"),              
    ("Andenne", "Andenne", "5300", "BE_nm_1284"),          

    # Flandre
    ("Antwerpen", "Anvers", "2000", "BE_a_310"),
    ("Gent", "Gand", "9000", "BE_foi_2551"),
    ("Brugge", "Brugge", "8000", "BE_foc_2292"),
    ("Leuven", "Leuven", "3000", "BE_bf_468"),
    ("Hasselt", "Hasselt", "3500", "BE_li_603"),
    ("Genk", "Genk", "3600", "BE_li_634"),
    ("Kortrijk", "Kortrijk", "8500", "BE_foc_2360"),
    ("Oostende", "Ostende", "8400", "BE_foc_2326"),
    ("Sint-Niklaas", "Sint-Niklaas", "9100", "BE_foi_2575"),
    ("Aalst", "Aalst", "9300", "BE_foi_2633"),
    ("Mechelen", "Mechelen", "2800", "BE_a_424"),
    ("Turnhout", "Turnhout", "2300", "BE_a_361"),
    ("Roeselare", "Roeselare", "8800", "BE_foc_2490"),
    ("Waregem", "Waregem", "8790", "BE_foc_2484"),
    ("Vilvoorde", "Vilvoorde", "1800", "BE_bf_272"),
    ("Halle", "Halle", "1500", "BE_bf_200"),
    ("Tienen", "Tienen", "3300", "BE_bf_541"),
    ("Tongeren", "Tongeren", "3700", "BE_li_686"),
    ("Lommel", "Lommel", "3920", "BE_li_800"),
    ("Beringen", "Beringen", "3580", "BE_li_629"),
]

HEADERS = {
    "User-Agent": "Mozilla/5.0"
}

session = requests.Session()
session.headers.update(HEADERS)


def clean_text(value):
    if value is None:
        return ""
    return " ".join(unescape(str(value)).replace("\xa0", " ").split()).strip()


def clean_address(value):
    if not value:
        return ""
    value = unescape(str(value)).replace("<br/>", ", ").replace("<br>", ", ")
    return " ".join(value.replace("\xa0", " ").split()).strip(" ,")


def to_float(value):
    try:
        if value in (None, ""):
            return None
        return float(str(value).replace(",", "."))
    except:
        return None


def fetch_with_retry(url, tries=3, timeout=20):
    last_error = None

    for attempt in range(1, tries + 1):
        try:
            r = session.get(url, timeout=timeout)
            r.raise_for_status()
            return r
        except Exception as e:
            last_error = e
            print(f"  Tentative {attempt}/{tries} échouée : {e}")
            if attempt < tries:
                time.sleep(3)

    raise last_error


def scrape_page(city_display, city_url, cp, location_id, fuel_label, fuel_code):
    if not location_id:
        print(f"Location ID manquant pour {city_display} ({cp}) -> ignoré")
        return []

    url = f"https://carbu.com/belgique//liste-stations-service/{fuel_code}/{quote(city_url)}/{cp}/{location_id}"
    print(f"Scraping {fuel_label} - {city_display} ({cp}) -> {location_id}")

    r = fetch_with_retry(url, tries=3, timeout=20)
    soup = BeautifulSoup(r.text, "lxml")

    stations = []

    for item in soup.select(".stationItem"):
        d = item.attrs

        if not d.get("data-name"):
            continue

        station_id = clean_text(d.get("data-id"))
        name = clean_text(d.get("data-name"))
        brand = clean_text(d.get("data-logo"))
        address = clean_address(d.get("data-address"))
        lat = to_float(d.get("data-lat"))
        lng = to_float(d.get("data-lng"))
        price = to_float(d.get("data-price"))
        url_station = clean_text(d.get("data-link"))
        fuel_name = clean_text(d.get("data-fuelname"))

        stations.append({
            "id": station_id,
            "name": name,
            "brand": brand,
            "address": address,
            "lat": lat,
            "lng": lng,
            "fuel": fuel_label,
            "fuel_name": fuel_name,
            "price": price,
            "url": url_station,
            "source_city": city_display,
            "source_cp": cp,
            "location_id": location_id
        })

    return stations


all_stations = {}
request_count = 0

for fuel_label, fuel_code in FUELS.items():
    for city_display, city_url, cp, location_id in LOCATIONS:
        try:
            results = scrape_page(city_display, city_url, cp, location_id, fuel_label, fuel_code)

            for s in results:
                key = f'{s["id"]}_{s["fuel"]}'
                all_stations[key] = s

        except Exception as e:
            print(f"Erreur ignorée pour {city_display} / {fuel_label} : {e}")

        request_count += 1
        time.sleep(2)

        if request_count % 5 == 0:
            print("Pause de sécurité...")
            time.sleep(5)

data = list(all_stations.values())

# tri pour avoir un JSON propre
data.sort(key=lambda x: (
    x["fuel"] or "",
    999999 if x["price"] is None else x["price"],
    x["name"] or ""
))

with open("stations.json", "w", encoding="utf-8") as f:
    json.dump(data, f, indent=2, ensure_ascii=False)

print(f"Done: {len(data)} stations sauvegardées dans stations.json")  