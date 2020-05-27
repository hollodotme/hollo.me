---
layout: default
title: PHP blog posts
tags: [PHP, blog posts]
permalink: /php/
---

{% assign year = 0 %}
{% assign slug = 'php' %}
{% for post in site.posts %}{% if post.slug == slug %}{% assign post_year = post.date | date: '%Y' %}{% if post_year != year %}{% assign year = post_year %}

## Posts in {{ post_year }}
{% endif %}
* {{ post.date | date: '%Y-%m-%d' }} &middot; [{{ post.title }}{% if post.subtitle %} - {{ post.subtitle }}{% endif %}]({{ post.url | relative_url }}){% endif %}{% endfor %}
