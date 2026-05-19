/**
 * City Autocomplete Component
 * Provides "City, Country" autocomplete for location inputs.
 */
(function () {
    // Comprehensive city database — City, Country
    var CITIES = [
        // Bangladesh
        "Dhaka, Bangladesh","Chittagong, Bangladesh","Khulna, Bangladesh","Rajshahi, Bangladesh",
        "Sylhet, Bangladesh","Rangpur, Bangladesh","Barisal, Bangladesh","Comilla, Bangladesh",
        "Gazipur, Bangladesh","Narayanganj, Bangladesh","Mymensingh, Bangladesh","Bogra, Bangladesh",
        "Cox's Bazar, Bangladesh","Jessore, Bangladesh","Dinajpur, Bangladesh","Tangail, Bangladesh",

        // India
        "Mumbai, India","Delhi, India","Bangalore, India","Hyderabad, India","Ahmedabad, India",
        "Chennai, India","Kolkata, India","Pune, India","Jaipur, India","Lucknow, India",
        "Kanpur, India","Nagpur, India","Indore, India","Thane, India","Bhopal, India",
        "Patna, India","Vadodara, India","Ghaziabad, India","Ludhiana, India","Agra, India",
        "Surat, India","Coimbatore, India","Kochi, India","Visakhapatnam, India","Chandigarh, India",

        // USA
        "New York, United States","Los Angeles, United States","Chicago, United States",
        "Houston, United States","Phoenix, United States","Philadelphia, United States",
        "San Antonio, United States","San Diego, United States","Dallas, United States",
        "San Jose, United States","Austin, United States","Jacksonville, United States",
        "San Francisco, United States","Seattle, United States","Denver, United States",
        "Washington, United States","Nashville, United States","Boston, United States",
        "Portland, United States","Las Vegas, United States","Miami, United States",
        "Atlanta, United States","Minneapolis, United States","Detroit, United States",

        // United Kingdom
        "London, United Kingdom","Birmingham, United Kingdom","Manchester, United Kingdom",
        "Glasgow, United Kingdom","Liverpool, United Kingdom","Leeds, United Kingdom",
        "Edinburgh, United Kingdom","Bristol, United Kingdom","Sheffield, United Kingdom",
        "Cardiff, United Kingdom","Oxford, United Kingdom","Cambridge, United Kingdom",
        "Nottingham, United Kingdom","Belfast, United Kingdom","Southampton, United Kingdom",

        // Canada
        "Toronto, Canada","Montreal, Canada","Vancouver, Canada","Calgary, Canada",
        "Edmonton, Canada","Ottawa, Canada","Winnipeg, Canada","Quebec City, Canada",
        "Hamilton, Canada","Halifax, Canada","Victoria, Canada",

        // Australia
        "Sydney, Australia","Melbourne, Australia","Brisbane, Australia","Perth, Australia",
        "Adelaide, Australia","Canberra, Australia","Gold Coast, Australia","Hobart, Australia",

        // Germany
        "Berlin, Germany","Munich, Germany","Frankfurt, Germany","Hamburg, Germany",
        "Cologne, Germany","Stuttgart, Germany","Dusseldorf, Germany","Leipzig, Germany",
        "Dortmund, Germany","Dresden, Germany","Hanover, Germany","Nuremberg, Germany",

        // France
        "Paris, France","Marseille, France","Lyon, France","Toulouse, France",
        "Nice, France","Nantes, France","Strasbourg, France","Montpellier, France",
        "Bordeaux, France","Lille, France","Rennes, France",

        // Spain
        "Madrid, Spain","Barcelona, Spain","Valencia, Spain","Seville, Spain",
        "Zaragoza, Spain","Malaga, Spain","Bilbao, Spain","Granada, Spain",

        // Italy
        "Rome, Italy","Milan, Italy","Naples, Italy","Turin, Italy","Florence, Italy",
        "Bologna, Italy","Venice, Italy","Genoa, Italy","Palermo, Italy",

        // Japan
        "Tokyo, Japan","Osaka, Japan","Yokohama, Japan","Nagoya, Japan","Sapporo, Japan",
        "Kyoto, Japan","Kobe, Japan","Fukuoka, Japan","Hiroshima, Japan",

        // China
        "Beijing, China","Shanghai, China","Guangzhou, China","Shenzhen, China",
        "Chengdu, China","Hangzhou, China","Wuhan, China","Xi'an, China",
        "Nanjing, China","Tianjin, China","Chongqing, China",

        // South Korea
        "Seoul, South Korea","Busan, South Korea","Incheon, South Korea",
        "Daegu, South Korea","Daejeon, South Korea","Gwangju, South Korea",

        // Brazil
        "Sao Paulo, Brazil","Rio de Janeiro, Brazil","Brasilia, Brazil",
        "Salvador, Brazil","Fortaleza, Brazil","Belo Horizonte, Brazil",
        "Curitiba, Brazil","Manaus, Brazil","Recife, Brazil",

        // Mexico
        "Mexico City, Mexico","Guadalajara, Mexico","Monterrey, Mexico",
        "Puebla, Mexico","Cancun, Mexico","Tijuana, Mexico",

        // Russia
        "Moscow, Russia","Saint Petersburg, Russia","Novosibirsk, Russia",
        "Yekaterinburg, Russia","Kazan, Russia",

        // Turkey
        "Istanbul, Turkey","Ankara, Turkey","Izmir, Turkey","Antalya, Turkey","Bursa, Turkey",

        // UAE
        "Dubai, United Arab Emirates","Abu Dhabi, United Arab Emirates",
        "Sharjah, United Arab Emirates","Ajman, United Arab Emirates",

        // Saudi Arabia
        "Riyadh, Saudi Arabia","Jeddah, Saudi Arabia","Mecca, Saudi Arabia",
        "Medina, Saudi Arabia","Dammam, Saudi Arabia",

        // Pakistan
        "Karachi, Pakistan","Lahore, Pakistan","Islamabad, Pakistan",
        "Rawalpindi, Pakistan","Faisalabad, Pakistan","Peshawar, Pakistan",

        // Malaysia
        "Kuala Lumpur, Malaysia","George Town, Malaysia","Johor Bahru, Malaysia",

        // Singapore
        "Singapore, Singapore",

        // Thailand
        "Bangkok, Thailand","Chiang Mai, Thailand","Phuket, Thailand","Pattaya, Thailand",

        // Indonesia
        "Jakarta, Indonesia","Surabaya, Indonesia","Bandung, Indonesia","Bali, Indonesia",

        // Philippines
        "Manila, Philippines","Cebu City, Philippines","Davao, Philippines",

        // Vietnam
        "Ho Chi Minh City, Vietnam","Hanoi, Vietnam","Da Nang, Vietnam",

        // South Africa
        "Cape Town, South Africa","Johannesburg, South Africa","Durban, South Africa",
        "Pretoria, South Africa",

        // Nigeria
        "Lagos, Nigeria","Abuja, Nigeria","Port Harcourt, Nigeria",

        // Egypt
        "Cairo, Egypt","Alexandria, Egypt","Giza, Egypt",

        // Kenya
        "Nairobi, Kenya","Mombasa, Kenya",

        // Argentina
        "Buenos Aires, Argentina","Cordoba, Argentina","Rosario, Argentina",

        // Colombia
        "Bogota, Colombia","Medellin, Colombia","Cali, Colombia",

        // Chile
        "Santiago, Chile","Valparaiso, Chile",

        // Peru
        "Lima, Peru","Cusco, Peru","Arequipa, Peru",

        // Netherlands
        "Amsterdam, Netherlands","Rotterdam, Netherlands","The Hague, Netherlands",
        "Utrecht, Netherlands","Eindhoven, Netherlands",

        // Belgium
        "Brussels, Belgium","Antwerp, Belgium","Ghent, Belgium",

        // Switzerland
        "Zurich, Switzerland","Geneva, Switzerland","Bern, Switzerland","Basel, Switzerland",

        // Sweden
        "Stockholm, Sweden","Gothenburg, Sweden","Malmo, Sweden",

        // Norway
        "Oslo, Norway","Bergen, Norway","Trondheim, Norway",

        // Denmark
        "Copenhagen, Denmark","Aarhus, Denmark",

        // Finland
        "Helsinki, Finland","Tampere, Finland",

        // Poland
        "Warsaw, Poland","Krakow, Poland","Wroclaw, Poland","Gdansk, Poland",

        // Portugal
        "Lisbon, Portugal","Porto, Portugal","Braga, Portugal",

        // Austria
        "Vienna, Austria","Salzburg, Austria","Graz, Austria",

        // Czech Republic
        "Prague, Czech Republic","Brno, Czech Republic",

        // Greece
        "Athens, Greece","Thessaloniki, Greece",

        // Ireland
        "Dublin, Ireland","Cork, Ireland","Galway, Ireland",

        // New Zealand
        "Auckland, New Zealand","Wellington, New Zealand","Christchurch, New Zealand",

        // Sri Lanka
        "Colombo, Sri Lanka","Kandy, Sri Lanka",

        // Nepal
        "Kathmandu, Nepal","Pokhara, Nepal",

        // Myanmar
        "Yangon, Myanmar","Mandalay, Myanmar",

        // Qatar
        "Doha, Qatar",

        // Kuwait
        "Kuwait City, Kuwait",

        // Bahrain
        "Manama, Bahrain",

        // Oman
        "Muscat, Oman",

        // Jordan
        "Amman, Jordan",

        // Lebanon
        "Beirut, Lebanon",

        // Israel
        "Tel Aviv, Israel","Jerusalem, Israel","Haifa, Israel",

        // Morocco
        "Casablanca, Morocco","Marrakech, Morocco","Rabat, Morocco",

        // Tunisia
        "Tunis, Tunisia",

        // Ghana
        "Accra, Ghana",

        // Ethiopia
        "Addis Ababa, Ethiopia",

        // Tanzania
        "Dar es Salaam, Tanzania",

        // Uganda
        "Kampala, Uganda",

        // Romania
        "Bucharest, Romania","Cluj-Napoca, Romania",

        // Hungary
        "Budapest, Hungary","Debrecen, Hungary",

        // Croatia
        "Zagreb, Croatia","Split, Croatia","Dubrovnik, Croatia",

        // Ukraine
        "Kyiv, Ukraine","Lviv, Ukraine","Odesa, Ukraine",

        // Cuba
        "Havana, Cuba",

        // Jamaica
        "Kingston, Jamaica",

        // Iceland
        "Reykjavik, Iceland"
    ];

    /**
     * Initialize autocomplete on a given input element.
     * @param {HTMLInputElement} input - The location input element.
     */
    function initCityAutocomplete(input) {
        if (!input) return;

        // Wrap the input in a relative container
        var wrapper = document.createElement('div');
        wrapper.className = 'city-ac-wrapper';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        // Create dropdown
        var dropdown = document.createElement('div');
        dropdown.className = 'city-ac-dropdown';
        wrapper.appendChild(dropdown);

        var activeIndex = -1;

        input.setAttribute('autocomplete', 'off');

        input.addEventListener('input', function () {
            var val = this.value.trim().toLowerCase();
            dropdown.innerHTML = '';
            activeIndex = -1;

            if (val.length < 1) {
                dropdown.classList.remove('open');
                return;
            }

            var matches = CITIES.filter(function (city) {
                return city.toLowerCase().indexOf(val) !== -1;
            }).slice(0, 8);

            if (matches.length === 0) {
                dropdown.classList.remove('open');
                return;
            }

            matches.forEach(function (city, idx) {
                var item = document.createElement('div');
                item.className = 'city-ac-item';
                // Highlight matching text
                var regex = new RegExp('(' + val.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
                item.innerHTML = city.replace(regex, '<strong>$1</strong>');
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    input.value = city;
                    dropdown.classList.remove('open');
                });
                dropdown.appendChild(item);
            });

            dropdown.classList.add('open');
        });

        input.addEventListener('keydown', function (e) {
            var items = dropdown.querySelectorAll('.city-ac-item');
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = Math.min(activeIndex + 1, items.length - 1);
                updateActive(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                updateActive(items);
            } else if (e.key === 'Enter' && activeIndex >= 0) {
                e.preventDefault();
                input.value = items[activeIndex].textContent;
                dropdown.classList.remove('open');
            } else if (e.key === 'Escape') {
                dropdown.classList.remove('open');
            }
        });

        input.addEventListener('blur', function () {
            setTimeout(function () {
                dropdown.classList.remove('open');
            }, 150);
        });

        input.addEventListener('focus', function () {
            if (this.value.trim().length >= 1) {
                input.dispatchEvent(new Event('input'));
            }
        });

        function updateActive(items) {
            items.forEach(function (item, i) {
                item.classList.toggle('active', i === activeIndex);
            });
            if (activeIndex >= 0 && items[activeIndex]) {
                items[activeIndex].scrollIntoView({ block: 'nearest' });
            }
        }
    }

    // Auto-init: find all inputs with class 'city-autocomplete'
    document.addEventListener('DOMContentLoaded', function () {
        var inputs = document.querySelectorAll('.city-autocomplete');
        inputs.forEach(function (input) {
            initCityAutocomplete(input);
        });
    });

    // Expose for manual init
    window.initCityAutocomplete = initCityAutocomplete;
})();
