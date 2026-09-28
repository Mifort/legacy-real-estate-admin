{extends file="layout.tpl"}

{block name="content"}
    <h2>Типы домов</h2>
    {if $houses}
        <div class="grid">
            {foreach $houses as $h}
                <div class="house">
                    <div class="house__name">{$h.name}</div>
                    <div class="house__meta">
                        Материал: {if $h.material}{$h.material}{else}—{/if}<br>
                        Квартир в продаже: {$h.count}
                    </div>
                </div>
            {/foreach}
        </div>
    {else}
        <p class="empty">Пока нет данных о домах.</p>
    {/if}

    <h2>Квартиры</h2>
    {if $flats}
        <div class="grid">
            {foreach $flats as $f}
                <div class="card">
                    <div class="card__photo"{if $f.photo} style="background-image:url('{$f.photo}')"{/if}>
                        {if !$f.photo}нет фото{/if}
                    </div>
                    <div class="card__body">
                        <div class="card__price">{$f.cost|number_format:0:'.':' '} ₽</div>
                        <div class="card__addr">{if $f.address}{$f.address}{else}Адрес не указан{/if}</div>
                        <div class="tags">
                            {if $f.house}<span class="tag">{$f.house}</span>{/if}
                            {if $f.material}<span class="tag">{$f.material}</span>{/if}
                            {if $f.view}<span class="tag">Вид: {$f.view}</span>{/if}
                        </div>
                        <div class="specs">
                            Площадь: {$f.area} м² · Этаж: {if $f.floor}{$f.floor}{else}—{/if}
                        </div>
                        {if $f.description}<div class="desc">{$f.description nofilter}</div>{/if}
                        {if $f.phone}<div class="specs">☎ {$f.phone}</div>{/if}
                    </div>
                </div>
            {/foreach}
        </div>
    {else}
        <p class="empty">Пока нет предложений.</p>
    {/if}
{/block}
