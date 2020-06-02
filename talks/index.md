---
layout: default
title: Talks
tags: [talks, community, conferences, user groups, meetup]
permalink: /talks/
---

<div class="row m-lg-5 m-md-3 justify-content-center">
	<div class="col-lg-6 col-md-8 col-sm-10 text-center">
		<p>
			If you like to see me speaking at a conference or at your local user group,
            please check my previous talks below or just ping me with a topic you like me to talk about.
		</p>
		<p>
			<a href="mailto:freelance@hollo.me" class="btn btn-warning btn-lg btn-block">Get in touch</a>
		</p>
	</div>
</div>

{% assign year = 0 %}
{% assign posts = site.posts | where: "slug", "talks" %}
{% for post in posts %}{% assign post_year = post.date | date: '%Y' %}{% if post_year != year %}{% assign year = post_year %}

## Talks in {{ post_year }}
{% endif %}
* {{ post.date | date: '%Y-%m-%d' }} &middot; [{{ post.title }}{% if post.subtitle %} - {{ post.subtitle }}{% endif %}]({{ post.url | relative_url }}){% endfor %}
