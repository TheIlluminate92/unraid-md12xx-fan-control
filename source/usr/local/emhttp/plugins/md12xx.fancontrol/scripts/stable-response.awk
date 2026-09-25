BEGIN { FS="\t" }

NR == FNR {
  low[$1]=$3
  device[$1]=$2
  next
}

FNR == 1 || !($2 in low) { next }

{
  address=$2
  previous[address]=latest[address]
  latest[address]=$4+0
  samples[address]++
  delta=latest[address]-low[address]
  pct=(low[address]>0 ? delta/low[address]*100 : 0)
  if (delta >= 250 && pct >= 10) responded[address]=1
}

END {
  responseCount=0
  for (address in responded) responseCount++
  if (responseCount != 1) exit

  for (address in responded) {
    if (samples[address] < 2) exit
    previousDelta=previous[address]-low[address]
    previousPct=(low[address]>0 ? previousDelta/low[address]*100 : 0)
    latestDelta=latest[address]-low[address]
    latestPct=(low[address]>0 ? latestDelta/low[address]*100 : 0)
    difference=latest[address]-previous[address]
    if (difference < 0) difference=-difference
    tolerance=latest[address]*0.10
    if (tolerance < 250) tolerance=250
    if (previousDelta < 250 || previousPct < 10 || latestDelta < 250 || latestPct < 10 || difference > tolerance) exit

    stableRpm=int((previous[address]+latest[address])/2+0.5)
    stableDelta=stableRpm-low[address]
    stablePct=(low[address]>0 ? stableDelta/low[address]*100 : 0)
    printf "%s\t%s\t%d\t%d\t%d\t%.1f\n", address, device[address], low[address], stableRpm, stableDelta, stablePct
  }
}
