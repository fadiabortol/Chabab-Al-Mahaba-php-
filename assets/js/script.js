const translations = {
    fr: {
        name: "Jeunes d'El Mahaba",
        slogan: "Amour - Solidarité - Développement",
        videoTitle: "Notre Parcours en 15 Minutes",
        storyTitle: "Notre histoire",
        storyText: "Une communauté enracinée dans l'amour, renforcée par la solidarité et engagée pour le développement.",
        footer: "Jeunes d'El Mahaba · Amour - Solidarité - Développement"
    },
    tr: {
        name: "El Mahaba Gençlik Derneği",
        slogan: "Sevgi - Dayanışma - Kalkınma",
        videoTitle: "15 Dakikada Yolculuğumuz",
        storyTitle: "Hikayemiz",
        storyText: "Sevgiyle kök salan, dayanışmayla güçlenen ve kalkınmaya adanmış bir topluluk.",
        footer: "El Mahaba Gençlik Derneği · Sevgi - Dayanışma - Kalkınma"
    },
    ar: {
        name: "شباب المحبة",
        slogan: "محبة - تضامن - تنمية",
        videoTitle: "رحلتنا في 15 دقيقة",
        storyTitle: "قصتنا",
        storyText: "مجتمعٌ أساسه المحبة، وقوته التضامن، وهدفه التنمية.",
        footer: "شباب المحبة · محبة - تضامن - تنمية"
    },
    en: {
        name: "Shabab Al-Mahaba",
        slogan: "Love - Solidarity - Development",
        videoTitle: "Our Journey in 15 Minutes",
        storyTitle: "Our Story",
        storyText: "A community rooted in love, strengthened by solidarity, and committed to development.",
        footer: "Shabab Al-Mahaba · Love - Solidarity - Development"
    }
};

const languageButtons = document.querySelectorAll("[data-language]");
const translatedElements = document.querySelectorAll("[data-copy]");
const video = document.querySelector(".video-frame iframe");

function setLanguage(language) {
    const translation = translations[language];
    if (!translation) {
        return;
    }

    document.documentElement.lang = language;
    document.documentElement.dir = language === "ar" ? "rtl" : "ltr";

    translatedElements.forEach((element) => {
        element.textContent = translation[element.dataset.copy];
    });

    video.title = `${translation.name} — ${translation.videoTitle}`;

    languageButtons.forEach((button) => {
        button.setAttribute("aria-pressed", String(button.dataset.language === language));
    });
}

languageButtons.forEach((button) => {
    button.addEventListener("click", () => setLanguage(button.dataset.language));
});

document.querySelectorAll(".story-section[data-post-url], .home-post-section[data-post-url]").forEach((post) => {
    const openPost = () => {
        window.location.href = post.dataset.postUrl;
    };

    post.addEventListener("click", (event) => {
        if (event.target.closest("a, button, input, select, textarea, .story-video")) {
            return;
        }

        openPost();
    });

    post.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            openPost();
        }
    });
});
